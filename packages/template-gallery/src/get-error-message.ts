/**
 * Extract a human-readable message from an unknown error value.
 *
 * WordPress api-fetch throws plain objects like { code, message, data }
 * rather than Error instances, so a naive String() produces "[object Object]".
 */
export function getErrorMessage(error: unknown): string {
	if (error instanceof Error) {
		return error.message;
	}
	if (typeof error === 'object' && error !== null && 'message' in error) {
		return String((error as Record<string, unknown>).message);
	}
	return String(error);
}
