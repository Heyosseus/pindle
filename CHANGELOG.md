# Changelog

All notable changes to Pindle are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- **Core.** `pindle_annotations` and `pindle_comments`, the `HasAnnotations`
  trait, the `DocumentResolver` contract with an attribute-reading default, the
  `PindleDocument` value object with lazily streamed sha256 hashing, ULID keys
  and soft deletes throughout.
- **Anchoring.** Rectangles stored in PDF user space with a bottom-left origin,
  in points, normalised on the way in and returned unchanged — proven by a
  round-trip through the model and, on the client, at four rotations and five
  zoom levels.
- **Orphan detection.** Every annotation records the sha256 of the document it
  was drawn on. When the bytes change, the annotation is served flagged and
  drawn with a warning rather than silently pointing somewhere else.
- **Authorisation.** `AnnotationPolicy` delegates every question to the owning
  model's policy through a configurable ability map. An unmapped ability denies.
- **HTTP.** Eight routes: the annotation and comment API, and a signed,
  expiring document stream with HTTP range support, re-authorised through the
  policy on every request.
- **Server-side geometry validation.** Page and coordinate bounds are read from
  the document where they can be read cheaply and fall back to configured
  ceilings where they cannot; anchor counts are capped.
- **Viewer.** EmbedPDF and PDFium via WebAssembly, opened by range request,
  with the marks, the comment thread, resolve and reopen, and a visible warning
  on orphans. Pre-compiled and committed; the host application never runs a
  build.
- **Adapters.** An optional `<livewire:pindle-viewer>` wrapper, and
  `PindleViewer`, `PindleEntry` and `PindlePlugin` for Filament. Neither
  Livewire nor Filament is ever a hard dependency.
- **Review state.** `$model->pindleReview()` and `pindleReviews()` return a
  `ReviewSummary` — open, resolved, orphaned, comment count and last activity —
  in three queries however many annotations there are, with the document hashed
  once rather than once per mark.
- **Re-anchoring.** When a document has been replaced, the viewer will search
  the new revision's text layer for the snippet a mark recorded and offer to
  move it. `POST /annotations/{id}/reanchor` performs the move, re-hashes
  against the new bytes and fires `AnnotationReanchored` with the hash it
  replaced. Never automatic: a person decides.
- **Filament review surface.** `PindleReviewColumn` and `PindleReviewEntry`
  show what is still open on each record's document, from an index screen or a
  record page.
- **`pindle:export`,** producing a document's review as markdown or JSON,
  ordered by page and then by when each point was raised.
- **Keyboard navigation** in the viewer: `n`/`j` and `p`/`k` step through the
  marks wanting attention, `r` resolves, `Escape` closes the thread.
- **Events.** `AnnotationCreated`, `AnnotationUpdated`, `AnnotationDeleted`,
  `AnnotationResolved`, `AnnotationReanchored` and `CommentPosted`, dispatched
  from the models so an importer is heard as clearly as a controller.
- **`pindle:prune`,** with an optional schedule, to forget what was deleted
  beyond a retention window.
