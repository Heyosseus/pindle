import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { Store, thread } from '../src/store.js';

const annotation = (overrides = {}) => ({
  id: 'a1',
  page: 1,
  type: 'highlight',
  rects: [{ x1: 1, y1: 2, x2: 3, y2: 4 }],
  orphaned: false,
  comments: [],
  ...overrides,
});

describe('the store', () => {
  it('takes a list response whole', () => {
    const store = new Store();

    store.replaceAll({
      data: [annotation(), annotation({ id: 'a2', page: 2 })],
      document: { hash: 'abc', size: 10 },
    });

    assert.equal(store.all().length, 2);
    assert.equal(store.document.hash, 'abc');
  });

  it('forgets what the previous document held', () => {
    const store = new Store();

    store.replaceAll({ data: [annotation()] });
    store.replaceAll({ data: [annotation({ id: 'a2' })] });

    assert.deepEqual(
      store.all().map((a) => a.id),
      ['a2'],
    );
  });

  it('copes with a response carrying nothing', () => {
    const store = new Store();

    store.replaceAll({});

    assert.deepEqual(store.all(), []);
    assert.equal(store.document, null);
  });

  it('groups by page', () => {
    const store = new Store();

    store.replaceAll({ data: [annotation(), annotation({ id: 'a2', page: 2 })] });

    assert.deepEqual(
      store.onPage(1).map((a) => a.id),
      ['a1'],
    );
    assert.deepEqual(store.onPage(3), []);
  });

  it('surfaces orphans rather than hiding them', () => {
    const store = new Store();

    store.replaceAll({ data: [annotation(), annotation({ id: 'a2', orphaned: true })] });

    assert.equal(store.all().length, 2, 'an orphan is still on the page');
    assert.deepEqual(
      store.orphans().map((a) => a.id),
      ['a2'],
    );
  });

  it('tells its listeners when anything changes', () => {
    const store = new Store();
    let calls = 0;

    const stop = store.subscribe(() => {
      calls += 1;
    });

    store.put(annotation());
    store.select('a1');
    store.remove('a1');

    assert.equal(calls, 3);

    stop();
    store.put(annotation());

    assert.equal(calls, 3, 'an unsubscribed listener hears nothing more');
  });

  it('drops the selection with the annotation it pointed at', () => {
    const store = new Store();

    store.put(annotation());
    store.select('a1');
    store.remove('a1');

    assert.equal(store.selectedId, null);
  });

  it('keeps a selection that points at something else', () => {
    const store = new Store();

    store.put(annotation());
    store.put(annotation({ id: 'a2' }));
    store.select('a2');
    store.remove('a1');

    assert.equal(store.selectedId, 'a2');
  });

  it('adds, edits and removes comments on a thread', () => {
    const store = new Store();

    store.put(annotation());
    store.addComment('a1', { id: 'c1', body: 'First', parent_id: null });
    store.addComment('a1', { id: 'c2', body: 'Reply', parent_id: 'c1' });

    assert.equal(store.get('a1').comments.length, 2);

    store.replaceComment('a1', { id: 'c1', body: 'Corrected', parent_id: null });

    assert.equal(store.get('a1').comments[0].body, 'Corrected');

    store.removeComment('a1', 'c1');

    assert.equal(store.get('a1').comments.length, 0, 'a removed root takes its replies with it');
  });

  it('ignores comment traffic for an annotation it does not hold', () => {
    const store = new Store();

    store.addComment('gone', { id: 'c1' });
    store.replaceComment('gone', { id: 'c1' });
    store.removeComment('gone', 'c1');

    assert.equal(store.get('gone'), null);
  });
});

describe('threading', () => {
  it('hangs replies off their parents in order', () => {
    const roots = thread([
      { id: 'c1', parent_id: null, body: 'First' },
      { id: 'c2', parent_id: 'c1', body: 'Reply' },
      { id: 'c3', parent_id: null, body: 'Second' },
      { id: 'c4', parent_id: 'c1', body: 'Another reply' },
    ]);

    assert.deepEqual(
      roots.map((r) => r.id),
      ['c1', 'c3'],
    );
    assert.deepEqual(
      roots[0].replies.map((r) => r.id),
      ['c2', 'c4'],
    );
    assert.deepEqual(roots[1].replies, []);
  });

  it('promotes an orphaned reply rather than losing it', () => {
    const roots = thread([{ id: 'c2', parent_id: 'gone', body: 'Reply to nothing' }]);

    assert.equal(roots.length, 1);
    assert.equal(roots[0].id, 'c2');
  });

  it('copes with nothing at all', () => {
    assert.deepEqual(thread(), []);
    assert.deepEqual(thread([]), []);
  });
});
