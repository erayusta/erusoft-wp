import { execFileSync } from 'node:child_process';
export function listTrackedFiles(root) {
  const out = execFileSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z'], { cwd: root });
  return out.toString('utf8').split('\0').filter(Boolean);
}
