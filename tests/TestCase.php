<?php

declare(strict_types=1);

namespace Pindle\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use Pindle\Pindle;
use Pindle\PindleServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PindleServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // The routes run behind the 'web' group, which encrypts cookies, which
        // needs a key. Nothing here is a secret; AES-256 wants exactly 32 bytes.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_pad('pindle-testing', 32, '-key')));

        $app['config']->set('database.default', 'testing');

        // SQLite in memory is enough: nothing Pindle does is dialect-specific, and
        // a suite that needs a server is a suite people stop running.
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',

            // Off by default on SQLite, which would let the suite pass while the
            // cascade that takes a thread down with its annotation never ran.
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('pindle.documents.disk', 'documents');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The host application's own tables. Pindle attaches to whatever these
        // are; the suite proves that by keying one of them by ULID rather than by
        // an auto-incrementing integer.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('delivery_pdf_path')->nullable();
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    /**
     * The scope hook is static, so it outlives the application that registered it.
     * Left behind, it would constrain the next test's queries.
     */
    protected function tearDown(): void
    {
        Pindle::flush();

        parent::tearDown();
    }
}
