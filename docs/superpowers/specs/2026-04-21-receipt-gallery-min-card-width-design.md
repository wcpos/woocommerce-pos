# Receipt Gallery Minimum Card Width Design

## Summary

Align the receipt template gallery card grid with the recently updated Extensions grid so gallery cards maintain a practical minimum width. This prevents the template description from being truncated and keeps the gallery footer actions, especially the “Use Template” button, on a single line.

## Problem

The receipt gallery currently uses a fixed responsive grid (`grid-cols-2 sm:grid-cols-3`). At narrower admin widths this allows cards to become too narrow, which causes two UX issues:

1. template descriptions are currently clamped and lose useful copy
2. the primary gallery action can wrap awkwardly and split “Use Template” across two lines

The Extensions page already solved the same class of problem by switching to an auto-fill grid with a minimum card width.

## Goals

- Keep receipt gallery cards wide enough to display their content comfortably.
- Show the full template description in the card body.
- Keep the gallery footer actions readable, with “Use Template” staying on one line.
- Reuse the same responsive pattern already adopted by the Extensions screen.

## Non-Goals

- Redesign the receipt gallery card visual style.
- Change template metadata, filtering logic, or preview behavior.
- Introduce new copy, new interactions, or a shared abstraction between packages as part of this small UI fix.

## Proposed Approach

### 1. Match the Extensions grid behavior

Update the receipt gallery grid from the fixed 2/3-column layout to an auto-fill layout using the same minimum width pattern as Extensions:

- `repeat(auto-fill, minmax(min(100%, 340px), 1fr))`

This keeps cards at or above the preferred width when space allows, but still prevents overflow when the container becomes narrower than 340px.

### 2. Let descriptions render fully

Remove the two-line clamp from the template card description so the existing description copy can wrap naturally across multiple lines.

The card body already uses a vertical flex layout, so allowing the description to grow should not break the overall card structure.

### 3. Keep footer actions on one row

Adjust the gallery footer/button layout so the footer remains horizontally aligned and the primary action label does not wrap. The expected behavior is:

- “Preview” remains a text action on the left
- “Use Template” remains a single-line primary button on the right
- cards may drop to fewer columns before the button is forced onto two lines

The simplest implementation is to keep the existing footer layout and prevent button text wrapping with utility classes, while relying on the wider minimum card width to avoid cramped layouts.

### 4. Keep loading state visually consistent

Update the gallery skeleton grid to use the same column logic as the live gallery so the loading layout matches the final rendered layout.

## Files Expected To Change

- `packages/template-gallery/src/screens/gallery-grid.tsx`
  - change gallery grid column sizing to the auto-fill/min-width pattern
- `packages/template-gallery/src/components/template-card.tsx`
  - remove description truncation and prevent gallery button label wrapping
- `packages/template-gallery/src/components/skeleton.tsx`
  - align skeleton grid column sizing with the live grid
- `packages/template-gallery/src/__tests__/template-card-layout.test.tsx`
  - add a focused regression test for the non-truncated description and non-wrapping gallery CTA styling

## Error Handling / Risk

This is a presentational change with low product risk.

Primary risks:

- cards becoming taller because descriptions are no longer clamped
- slight changes to the number of cards shown per row at some viewport widths

These are acceptable and intended trade-offs because they directly improve readability and action clarity.

## Testing Strategy

### Manual verification

Verify in WP Admin > POS > Settings > Extensions / receipt gallery area that:

- cards no longer collapse to overly narrow widths
- the full description is visible for templates like Branded Receipt and Detailed Receipt
- the “Use Template” button stays on one line
- the grid drops to fewer columns gracefully as width shrinks
- the loading skeleton uses the same grid behavior

### Automated verification

Add a focused regression test in `packages/template-gallery/src/__tests__/template-card-layout.test.tsx` that renders a gallery card and asserts the rendered markup no longer uses the description clamp class and does apply a no-wrap class to the primary gallery CTA. This keeps the bug covered without introducing broad UI test infrastructure.

## Acceptance Criteria

- Receipt gallery cards use a minimum-width responsive grid similar to the Extensions page.
- Template descriptions are no longer truncated in the gallery cards.
- The “Use Template” button does not wrap onto two lines.
- No new layout overflow is introduced at narrow widths.
- Skeleton and live gallery grid behavior stay visually aligned.
