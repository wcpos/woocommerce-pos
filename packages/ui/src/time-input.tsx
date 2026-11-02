import * as React from 'react';

import { TextInput, type TextInputProps } from './text-input';

export const TimeInput = React.forwardRef<HTMLInputElement, TextInputProps>((props, ref) => (
	<TextInput {...props} ref={ref} type="time" />
));
TimeInput.displayName = 'TimeInput';
