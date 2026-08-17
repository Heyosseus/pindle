/**
 * What the viewer knows about the annotations on screen.
 *
 * Kept separate from both the API and the DOM so that "what is on this page"
 * and "how it is drawn" are answerable without each other -- which is what
 * makes the overlay a pure function of state and the redraw after a Livewire
 * navigation a matter of replaying it.
 */
export class Store {
  constructor() {
    this.annotations = new Map();
    this.document = null;
    this.selectedId = null;
    this.listeners = new Set();
  }

  /** Subscribe to changes; returns the unsubscribe. */
  subscribe(listener) {
    this.listeners.add(listener);

    return () => this.listeners.delete(listener);
  }

  emit() {
    for (const listener of this.listeners) {
      listener(this);
    }
  }

  replaceAll(payload) {
    this.annotations.clear();

    for (const annotation of payload.data || []) {
      this.annotations.set(annotation.id, annotation);
    }

    this.document = payload.document || null;

    this.emit();
  }

  put(annotation) {
    this.annotations.set(annotation.id, annotation);
    this.emit();
  }

  remove(id) {
    this.annotations.delete(id);

    if (this.selectedId === id) {
      this.selectedId = null;
    }

    this.emit();
  }

  select(id) {
    this.selectedId = id;
    this.emit();
  }

  get(id) {
    return this.annotations.get(id) || null;
  }

  /** Everything anchored to one page, oldest first. */
  onPage(page) {
    return this.all().filter((annotation) => annotation.page === page);
  }

  all() {
    return [...this.annotations.values()];
  }

  /**
   * The annotations whose document has been replaced since they were drawn.
   *
   * Surfaced rather than filtered out. An orphan is still somebody's objection
   * to something; hiding it would lose the objection, and drawing it where it
   * says would attach it to whatever now sits at those coordinates.
   */
  orphans() {
    return this.all().filter((annotation) => annotation.orphaned);
  }

  addComment(annotationId, comment) {
    const annotation = this.get(annotationId);

    if (!annotation) {
      return;
    }

    annotation.comments = [...(annotation.comments || []), comment];

    this.emit();
  }

  replaceComment(annotationId, comment) {
    const annotation = this.get(annotationId);

    if (!annotation) {
      return;
    }

    annotation.comments = (annotation.comments || []).map((existing) =>
      existing.id === comment.id ? comment : existing,
    );

    this.emit();
  }

  removeComment(annotationId, commentId) {
    const annotation = this.get(annotationId);

    if (!annotation) {
      return;
    }

    // A reply hangs off its parent, so removing a root takes its replies with
    // it -- the same rule the database's cascade enforces.
    annotation.comments = (annotation.comments || []).filter(
      (comment) => comment.id !== commentId && comment.parent_id !== commentId,
    );

    this.emit();
  }
}

/**
 * A thread arranged for display: roots in order, each with its replies.
 *
 * Threading is one level deep, so this is a group-by rather than a tree walk. A
 * reply whose parent is not in the list -- because the parent was removed in
 * another tab -- is promoted to a root rather than dropped, since losing a
 * comment silently is worse than showing it slightly out of place.
 */
export function thread(comments = []) {
  const roots = [];
  const byId = new Map();

  for (const comment of comments) {
    if (!comment.parent_id) {
      const root = { ...comment, replies: [] };

      byId.set(comment.id, root);
      roots.push(root);
    }
  }

  for (const comment of comments) {
    if (!comment.parent_id) {
      continue;
    }

    const parent = byId.get(comment.parent_id);

    if (parent) {
      parent.replies.push(comment);
    } else {
      roots.push({ ...comment, replies: [] });
    }
  }

  return roots;
}
