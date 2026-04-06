import type { Color } from '@asamuzakjp/css-color';

// Update test usage pattern
describe('Color Picker', () => {
  it('should handle color parsing', async () => {
    const color = await import('@asamuzakjp/css-color');
    expect(color.name).toBeDefined();
  });
});

// If test requires side effects, add import:
import { getColorProvider } from '../components/ColorProvider';