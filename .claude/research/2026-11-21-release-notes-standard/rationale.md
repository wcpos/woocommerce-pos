# Rationale (laps 5 and 6)

Every changed claim was rechecked against commits-v2.0.3.txt and commits-v2.0.5.txt. Checks that passed on lap 6:
- no bold, and no italic (the new grep finds nothing);
- the longest readme bullet is 150 characters after `* `;
- only headings start with `=`;
- the 2.0.0 extraction leaks only `== Upgrade Notice ==`;
- the 2.0.3, 2.0.4 and 2.0.5 examples and the notice (296 characters) match the files exactly;
- the standard is 249 lines.

## Lap 6 (coordinator rulings 1-11; nothing else changed)
1. Done, with one change. The ruled error-report line measured 151 characters after `* `, so "and hide" became ", hiding" (150). "Crash reports" matches #2700's fatal-error events.
2. Done, exactly as ruled. #2713: off-list visible order meta is written as pushed.
3. Done. This uses the editor's 149-character line, with "Missing parameter(s): username" (#2704; today's text).
4. Done, unadjusted. The 2.0.0 source says "with jsDelivr as the fallback" and "signed and checked before it runs", so the ruled line contradicts nothing.
5. Done. The line now says "POS > Settings".
- Order rule: done. Group (2) adds "or was stored wrong". Group (4) names the loosened-guard line, and the "If you …" placement rule is added. In 2.0.5, the returned-item line and its template line now sit above "New:", and the custom-fields line comes after it.
6. Done. The #2713 bullet uses the dev judge's wording. #2713 says "hidden foreign meta stays protected as before", and #2664 says existing hidden meta is not overwritten. The rest of the bullet is unchanged.
7. Done. The figure is deleted, and the figure rule is now the before/after pair. The table row and the 2.0.3 readme line are unchanged.
8. Done. The checklist adds an italic grep. 9. Done. The checklist now says to remove the leaked `== Upgrade Notice ==` line from that release body by hand.
10. Done. "Older line:" is its own paragraph. 11. Done. The Upgrade Notice rule adds the order for cutting when both notices do not fit.
- Declined per the coordinator: the extra `_pos_user` Developer bullet (no double listing), "in WP Admin" on the reassign line, and the shop-NICE log naming.

## Lap 5 (summary)
- Editor MUST-FIX 1-4:
  - The New: line drops "every". It keeps "completed" (#2719 title; round 3 writes no record for legacy orders).
  - 2.0.3 says "hidden order data".
  - The "You saved" line is replaced by the row "guards a case the same PR introduced".
  - The WooCommerce wording comes from #2704.
- Shop MUST-FIX 1-5:
  - The custom-fields line says "the field", not "the order", because core fields are still hashed.
  - The loosened-guard row has a readme cell.
  - The error-report and sign-in lines are reworded (#2700, #2656).
- Dev MUST-FIX 1-5:
  - #2713 is listed once, under Upgrade.
  - That Upgrade line names its reader and action.
  - Only the 2.0.2 figures remain.
  - The #2719 Developer bullet keeps only `order_cashier_reassignment`.
  - The release.yml test is spelled out.
- NICEs: dev NICE 1 (the action flag in the lead sentence) was done. Every other NICE was declined under the ruling.
