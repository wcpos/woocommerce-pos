# Access settings: Restore defaults + mixed-state checkbox (mockups, 2026-09-17)

Preview canvas (Paul only, not durable): https://claude.ai/artifact/K2UczQkek3U4eSRPKvgade

Artboards (open any `.dc.html` in a browser; they are self-contained apart from the canvas runtime):

1. `Main.dc.html` — the Access screen as it renders today for the Cashier role.
2. `Proposed.dc.html` — "Restore defaults" button beside the role heading, amber dot on roles changed from the WCPOS defaults, mixed-state checkbox with an explanatory hint.
3. `Confirm.dc.html` — confirmation dialog listing Grant / Remove / Keep before writing.
4. `Variants.dc.html` — the three checkbox states and three copy options for the mixed state.

Recommended copy for the mixed state (option A):
"Some permissions are missing (3 of 7 granted), so the POS may refuse product edits. Tick to grant the rest, or review them under Advanced."

Implementation notes:
- The shared Checkbox already sets the native `indeterminate` property; wp-admin's stylesheet hides it. Needs an `:indeterminate::before` rule in the settings bundle.
- No "defaults" endpoint exists. Restore needs a settings action that reads `Activator::role_capability_definition()`, returns the grant/remove diff for the dialog, and writes it. For roles with no POS defaults (Editor etc.) restore means "remove all POS privileges" and the dialog must say so.
- Related bug: every version bump re-runs `single_activate()`, which re-adds every default cashier capability and undoes Access-screen removals. Restore is only meaningful once that resync grants new defaults only.
