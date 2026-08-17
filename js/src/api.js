/**
 * The client for the eight routes in the HTTP surface.
 *
 * Everything the viewer does to the server goes through here, so there is one
 * place that knows the URL shapes, one place that carries the CSRF token, and
 * one place that decides what a failure looks like. A fetch scattered through
 * the rendering code would be a fetch nobody remembers to authenticate.
 */

export class PindleApiError extends Error {
  constructor(status, payload) {
    super(payload?.message || `Pindle request failed with status ${status}`);

    this.name = 'PindleApiError';
    this.status = status;
    this.errors = payload?.errors || {};
  }
}

export class Api {
  /**
   * @param {object} config
   * @param {string} config.base       Prefix the routes live under, e.g. "/pindle".
   * @param {string} config.csrfToken  The application's CSRF token.
   * @param {string} config.annotatableType
   * @param {string} config.annotatableId
   * @param {string} config.documentKey
   */
  constructor(config) {
    this.base = String(config.base || '/pindle').replace(/\/$/, '');
    this.csrfToken = config.csrfToken || '';
    this.annotatableType = config.annotatableType;
    this.annotatableId = String(config.annotatableId);
    this.documentKey = config.documentKey || 'default';
    this.fetch = config.fetch || globalThis.fetch.bind(globalThis);
  }

  /** Everything written on this document, and the document's own hash. */
  async list() {
    const query = new URLSearchParams({
      annotatable_type: this.annotatableType,
      annotatable_id: this.annotatableId,
      document_key: this.documentKey,
    });

    return this.send('GET', `${this.base}/annotations?${query}`);
  }

  create(annotation) {
    return this.send('POST', `${this.base}/annotations`, {
      annotatable_type: this.annotatableType,
      annotatable_id: this.annotatableId,
      document_key: this.documentKey,
      ...annotation,
    });
  }

  update(id, changes) {
    return this.send('PATCH', `${this.base}/annotations/${encodeURIComponent(id)}`, changes);
  }

  destroy(id) {
    return this.send('DELETE', `${this.base}/annotations/${encodeURIComponent(id)}`);
  }

  resolve(id, resolved) {
    return this.update(id, { resolved });
  }

  comment(annotationId, body, parentId = null) {
    return this.send('POST', `${this.base}/annotations/${encodeURIComponent(annotationId)}/comments`, {
      body,
      parent_id: parentId,
    });
  }

  editComment(id, body) {
    return this.send('PATCH', `${this.base}/comments/${encodeURIComponent(id)}`, { body });
  }

  deleteComment(id) {
    return this.send('DELETE', `${this.base}/comments/${encodeURIComponent(id)}`);
  }

  async send(method, url, body) {
    const response = await this.fetch(url, {
      method,
      // The routes run in the web guard, so the session cookie has to travel.
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(this.csrfToken ? { 'X-CSRF-TOKEN': this.csrfToken } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.status === 204) {
      return null;
    }

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
      throw new PindleApiError(response.status, payload);
    }

    return payload;
  }
}
