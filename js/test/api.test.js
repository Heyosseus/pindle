import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { Api, PindleApiError } from '../src/api.js';

/** A fetch that records what it was asked and answers with what it was given. */
function recordingFetch(response = { ok: true, status: 200, body: {} }) {
  const calls = [];

  const fetch = async (url, init) => {
    calls.push({ url: String(url), ...init });

    return {
      ok: response.ok,
      status: response.status,
      json: async () => response.body,
    };
  };

  return { fetch, calls };
}

const make = (overrides = {}) => {
  const { fetch, calls } = recordingFetch(overrides.response);

  const api = new Api({
    base: '/pindle',
    csrfToken: 'token-123',
    annotatableType: 'App\\Models\\Invoice',
    annotatableId: 42,
    documentKey: 'delivery_note',
    fetch,
    ...overrides.config,
  });

  return { api, calls };
};

describe('the api client', () => {
  it('asks for one document of one model', async () => {
    const { api, calls } = make();

    await api.list();

    const url = new URL(calls[0].url, 'http://host');

    assert.equal(url.pathname, '/pindle/annotations');
    assert.equal(url.searchParams.get('annotatable_type'), 'App\\Models\\Invoice');
    assert.equal(url.searchParams.get('annotatable_id'), '42');
    assert.equal(url.searchParams.get('document_key'), 'delivery_note');
  });

  it('carries the csrf token and the session cookie on every write', async () => {
    const { api, calls } = make();

    await api.create({ page: 1, type: 'highlight', rects: [] });

    assert.equal(calls[0].method, 'POST');
    assert.equal(calls[0].credentials, 'same-origin');
    assert.equal(calls[0].headers['X-CSRF-TOKEN'], 'token-123');
    assert.equal(calls[0].headers['Content-Type'], 'application/json');
  });

  it('sends no csrf header when the page never gave it one', async () => {
    const { api, calls } = make({ config: { csrfToken: '' } });

    await api.list();

    assert.equal('X-CSRF-TOKEN' in calls[0].headers, false);
  });

  it('names the model on a create so the server need not guess', async () => {
    const { api, calls } = make();

    await api.create({ page: 3, type: 'note', rects: [{ x1: 1, y1: 2, x2: 3, y2: 4 }] });

    const body = JSON.parse(calls[0].body);

    assert.equal(body.annotatable_id, '42');
    assert.equal(body.document_key, 'delivery_note');
    assert.equal(body.page, 3);
  });

  it('sends no body at all on a read or a delete', async () => {
    const { api, calls } = make();

    await api.list();
    await api.destroy('a1');

    assert.equal(calls[0].body, undefined);
    assert.equal(calls[1].body, undefined);
    assert.equal('Content-Type' in calls[1].headers, false);
  });

  it('escapes an id rather than pasting it into a path', async () => {
    const { api, calls } = make();

    await api.update('../../etc/passwd', { color: '#fde047' });

    assert.ok(calls[0].url.endsWith('/pindle/annotations/..%2F..%2Fetc%2Fpasswd'));
  });

  it('settles and reopens through the same endpoint', async () => {
    const { api, calls } = make();

    await api.resolve('a1', true);

    assert.equal(calls[0].method, 'PATCH');
    assert.deepEqual(JSON.parse(calls[0].body), { resolved: true });
  });

  it('posts a comment and a reply', async () => {
    const { api, calls } = make();

    await api.comment('a1', 'This does not match.');
    await api.comment('a1', 'Corrected.', 'c1');

    assert.ok(calls[0].url.endsWith('/pindle/annotations/a1/comments'));
    assert.equal(JSON.parse(calls[0].body).parent_id, null);
    assert.equal(JSON.parse(calls[1].body).parent_id, 'c1');
  });

  it('edits and withdraws a comment', async () => {
    const { api, calls } = make();

    await api.editComment('c1', 'Withdrawn.');
    await api.deleteComment('c1');

    assert.equal(calls[0].method, 'PATCH');
    assert.ok(calls[0].url.endsWith('/pindle/comments/c1'));
    assert.equal(calls[1].method, 'DELETE');
  });

  it('reads nothing out of a no-content answer', async () => {
    const { api } = make({ response: { ok: true, status: 204, body: undefined } });

    assert.equal(await api.destroy('a1'), null);
  });

  it('raises the server\'s own message and field errors', async () => {
    const { api } = make({
      response: {
        ok: false,
        status: 422,
        body: { message: 'The page is outside the range.', errors: { page: ['too big'] } },
      },
    });

    await assert.rejects(
      () => api.create({ page: 9999 }),
      (error) => {
        assert.ok(error instanceof PindleApiError);
        assert.equal(error.status, 422);
        assert.equal(error.message, 'The page is outside the range.');
        assert.deepEqual(error.errors.page, ['too big']);

        return true;
      },
    );
  });

  it('still raises when the failure carried no json at all', async () => {
    const { fetch } = (() => {
      const f = async () => ({
        ok: false,
        status: 500,
        json: async () => {
          throw new Error('not json');
        },
      });

      return { fetch: f };
    })();

    const api = new Api({ annotatableType: 'x', annotatableId: '1', fetch });

    await assert.rejects(() => api.list(), /status 500/);
  });

  it('tolerates a base with a trailing slash', async () => {
    const { api, calls } = make({ config: { base: '/pindle/' } });

    await api.list();

    assert.ok(calls[0].url.startsWith('/pindle/annotations?'));
  });

  it('defaults to the shipped prefix and document key', async () => {
    const { fetch, calls } = recordingFetch();

    const api = new Api({ annotatableType: 'x', annotatableId: 1, fetch });

    await api.list();

    const url = new URL(calls[0].url, 'http://host');

    assert.equal(url.pathname, '/pindle/annotations');
    assert.equal(url.searchParams.get('document_key'), 'default');
  });
});
