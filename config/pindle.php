<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When this is false Pindle registers nothing at all: no routes, no Blade
    | directive, no Filament field. Disabled means absent rather than
    | present-and-declining, so there is no surface left to reach.
    |
    */

    'enabled' => env('PINDLE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | The disk a model's PDF path is read from when the model does not name one
    | of its own. Keep it private. "local" is, "public" is not, and pointing this
    | at a public disk hands out every document you thought was behind a policy.
    |
    | Pindle streams bytes through a signed, expiring route and re-authorises on
    | every request; it never hands out a disk URL. The TTL is seconds, and it
    | wants to be short: it only has to outlive the browser's first fetch, since
    | PDFium re-uses the same URL for the range requests that follow.
    |
    */

    'documents' => [
        'disk' => env('PINDLE_DISK', 'local'),
        'url_ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Where the JSON API and the document stream live. The middleware is your
    | application's, unchanged -- Pindle authorises through your policies rather
    | than through a stack of its own, so an empty list here means the endpoints
    | are still governed, just not authenticated.
    |
    | The throttle applies to writes only. Rendering a page of a large PDF is a
    | burst of range requests, so a limit tight enough to matter for writing
    | would break reading; posting a comment is one request a person makes, which
    | is the shape a rate limit is actually for. Either a rate ("60,1") or the
    | name of a limiter you registered. Null means you would rather do it
    | yourself.
    |
    */

    'routes' => [
        'prefix' => env('PINDLE_PATH', 'pindle'),
        'domain' => env('PINDLE_DOMAIN'),
        'middleware' => ['web', 'auth'],
        'throttle' => env('PINDLE_THROTTLE', '60,1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap either for a subclass to add relations, scopes or casts of your own.
    | Pindle resolves them through here rather than referencing the classes
    | directly, so a replacement is picked up everywhere at once.
    |
    */

    'models' => [
        'annotation' => Pindle\Models\Annotation::class,
        'comment' => Pindle\Models\Comment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorisation
    |--------------------------------------------------------------------------
    |
    | Every endpoint asks the policy about the *owning model* -- the invoice, the
    | contract, the report -- not about the annotation. That is deliberate: your
    | application already decides who may see an invoice, and an annotation on it
    | should not need a second, parallel answer that can drift out of step.
    |
    | The map below is how Pindle's five questions reach your existing policy. Out
    | of the box, seeing a document is enough to read its annotations, and being
    | able to edit the document is what lets you write on it. Point any of them at
    | an ability of your own -- "annotate", say -- to separate the two.
    |
    */

    'policy' => [
        'annotation' => Pindle\Policies\AnnotationPolicy::class,
        'abilities' => [
            'viewAny' => 'view',
            'create' => 'update',
            'update' => 'update',
            'delete' => 'update',
            'resolve' => 'update',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Annotations
    |--------------------------------------------------------------------------
    |
    | Geometry is validated on the server, because the client that drew it is not
    | a party you can trust about where it drew. A highlight over three lines is
    | three rectangles, so the cap is per annotation rather than per page; the
    | default is generous enough for a paragraph and small enough that a hostile
    | client cannot post a megabyte of JSON as one note.
    |
    */

    'annotations' => [
        'types' => ['highlight', 'note', 'area', 'ink'],
        'max_rects' => 64,
        'max_pages' => 5000,
        'colors' => ['#fde047', '#86efac', '#93c5fd', '#f9a8d4', '#fdba74'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Comments
    |--------------------------------------------------------------------------
    |
    | Bodies are stored exactly as they were typed and escaped when rendered. No
    | HTML and no markdown: a comment thread that renders markup is an XSS hole
    | with a feature request attached, and nobody has asked for bold text on a
    | highlight yet.
    |
    | Threading stops at one reply level. Replying to a reply attaches to its
    | parent instead, which is what keeps the margin of a page readable.
    |
    */

    'comments' => [
        'max_length' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Deleting an annotation soft-deletes it, so "who removed the objection, and
    | when" survives the removal. `pindle:prune` is what eventually forgets:
    | anything soft-deleted longer ago than the retention window, in days.
    |
    | A null schedule means your application registers the command itself and
    | Pindle stays out of your scheduler.
    |
    */

    'pruning' => [
        'enabled' => env('PINDLE_PRUNING', false),
        'retain_days' => 90,
        'schedule' => 'daily',
    ],

];
