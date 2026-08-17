# Security

## Reporting

If you find a security issue, please email **ratiruxadzee@gmail.com** rather
than opening a public issue. You will get an acknowledgement within a few days.

## What Pindle guarantees

- **Documents are never served from a public disk.** Bytes go through a signed,
  expiring route, and the signature is never the authorisation: the policy is
  asked again, on every request, about the model that owns the document. A link
  minted while somebody had access stops working the moment they lose it.
- **A signed link is not a bearer token.** It records who it was minted for and
  is refused to anybody else, even somebody who could have minted their own.
- **Annotations are reachable only through the model they are written on**, so
  tenant isolation is the application's existing isolation rather than a second
  copy of it. All eight routes are covered by a cross-tenant denial test.
- **The client is not trusted about geometry.** Pages and coordinates are
  validated server-side against the document where it can be read and against
  configured ceilings where it cannot; anchor counts are capped.
- **The client is not trusted about the document hash either.** It is taken from
  the bytes on the server, so an annotation cannot be minted that never orphans.
- **Comment bodies are stored raw and rendered as text.** No HTML, no markdown,
  on the server or in the viewer.
- **The morph is resolved through Laravel's morph map**, never treated as a
  class name to instantiate.

## What it asks of you

Keep the disk private. `local` is; `public` is not. Pindle will not stop you
pointing it at a public disk, and doing so hands out every document you thought
was behind a policy.
