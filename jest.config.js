import { join, resolve } from 'path';
import type { Config } from 'jest';
import color from '@asamuzakjp/css-color';

const config: Config = {
  rootDir: resolve(__dirname, '..'),
  roots: [join(__dirname, '..', 'template-editor')],
  collectCoverageFrom: [join(__dirname, '..', 'template-editor', '**', '*.{js,jsx,ts,tsx}')],
  coverageReporters: ['json', 'lcov', 'html'],
  testMatch: [join(__dirname, '..', 'template-editor', '**', '__tests__', '*.{js,jsx,ts,tsx}')],
  setupFilesAfterEnv: [join(__dirname, '..', 'template-editor', 'jest.setup.js')],
  transform: {},
  testURL: 'http://localhost/',
  transformIgnorePatterns: [join(__dirname, '..', 'template-editor', 'assets', '.+\.css$')],
};

export default config;
@asamuzakjp/css-color should be compatible with ESM context now.