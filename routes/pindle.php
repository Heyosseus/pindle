<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pindle\Http\Controllers\AnnotationController;
use Pindle\Http\Controllers\CommentController;
use Pindle\Http\Controllers\DocumentController;

/*
 * Registered under the configured prefix, domain and middleware by the service
 * provider. Nothing here adds a guard of its own beyond the signature on the
 * document route -- authorisation is the policy's job, on every endpoint, and a
 * second mechanism would only be a second thing to keep in step.
 */

/*
 * The parameter is named `document` rather than `signature`: Laravel reserves
 * `signature` on any signed route -- it is the query parameter the signature
 * itself travels in -- and generating one with a route parameter of that name
 * throws. The token in the path is the payload; the signature over it is
 * appended by Laravel.
 */
Route::get('documents/{document}', DocumentController::class)
    ->middleware('signed')
    ->name('pindle.documents.show');

Route::get('annotations', [AnnotationController::class, 'index'])->name('pindle.annotations.index');
Route::post('annotations', [AnnotationController::class, 'store'])->name('pindle.annotations.store');
Route::patch('annotations/{annotation}', [AnnotationController::class, 'update'])->name('pindle.annotations.update');
Route::delete('annotations/{annotation}', [AnnotationController::class, 'destroy'])->name('pindle.annotations.destroy');

Route::post('annotations/{annotation}/comments', [CommentController::class, 'store'])->name('pindle.comments.store');
Route::patch('comments/{comment}', [CommentController::class, 'update'])->name('pindle.comments.update');
Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('pindle.comments.destroy');
