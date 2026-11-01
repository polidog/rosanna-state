import { spawn, type ChildProcess } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

export interface PhpServer {
  readonly url: string;
  /** 起動からのサーバーの出力。リクエストの行と PHP のエラー */
  log(): string;
  stop(): Promise<void>;
}

const ROUTER = resolve(__dirname, '../fixtures/app.php');

/** ポートは OS に選ばせ、PHP が出力した値を読む。ワーカーごとに起動しても衝突しない */
export async function startPhpServer(router: string = ROUTER, env: NodeJS.ProcessEnv = {}): Promise<PhpServer> {
  const sessions = mkdtempSync(join(tmpdir(), 'rosanna-browser-'));
  const php = spawn(
    'php',
    ['-d', `session.save_path=${sessions}`, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', '127.0.0.1:0', router],
    { stdio: ['ignore', 'pipe', 'pipe'], env: { ...process.env, ...env } },
  );
  const killOnExit = () => php.kill('SIGKILL');
  process.once('exit', killOnExit);

  let output = '';
  const url = await new Promise<string>((ready, failed) => {
    const timer = setTimeout(() => failed(new Error(`php -S did not start:\n${output}`)), 15_000);
    const onData = (chunk: Buffer) => {
      output += chunk.toString();
      const started = output.match(/Development Server \((http:\/\/[^)]+)\) started/);
      if (started) {
        clearTimeout(timer);
        ready(started[1]);
      }
    };
    php.stdout.on('data', onData);
    php.stderr.on('data', onData);
    php.once('error', failed);
    php.once('exit', (code) => failed(new Error(`php -S exited with ${code}:\n${output}`)));
  });

  return {
    url,
    log: () => output,
    stop: async () => {
      await stop(php);
      process.removeListener('exit', killOnExit);
      rmSync(sessions, { recursive: true, force: true });
    },
  };
}

async function stop(child: ChildProcess): Promise<void> {
  if (child.exitCode !== null || child.signalCode !== null) return;
  await new Promise<void>((stopped) => {
    const force = setTimeout(() => child.kill('SIGKILL'), 3_000);
    child.once('exit', () => {
      clearTimeout(force);
      stopped();
    });
    child.kill('SIGTERM');
  });
}
