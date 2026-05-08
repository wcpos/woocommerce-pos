type ReactActGlobal = typeof globalThis & {
	IS_REACT_ACT_ENVIRONMENT: boolean;
};

(globalThis as ReactActGlobal).IS_REACT_ACT_ENVIRONMENT = true;
