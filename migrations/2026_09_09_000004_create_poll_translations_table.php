<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Created unconditionally so installing fof/polls later needs no re-migration; the
// FK to polls is only added when that table exists at migrate time. Lookups always
// go through poll_id, so orphans are harmless (fof:translate:prune removes them).
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('poll_translations')) {
            $withPolls = $schema->hasTable('polls');

            $schema->create('poll_translations', function (Blueprint $table) use ($withPolls) {
                $table->increments('id');
                $table->unsignedInteger('poll_id');
                $table->string('language', 10);
                $table->text('question');
                $table->text('subtitle')->nullable();
                $table->text('image_alt')->nullable();
                $table->string('provider');
                $table->boolean('update_needed')->default(false);
                $table->unsignedInteger('edited_by')->nullable();
                $table->timestamps();

                $table->unique(['poll_id', 'language']);
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();

                if ($withPolls) {
                    $table->foreign('poll_id')->references('id')->on('polls')->cascadeOnDelete();
                }
            });

            return;
        }

        if (! $schema->hasColumn('poll_translations', 'update_needed')) {
            $schema->table('poll_translations', function (Blueprint $table) {
                $table->boolean('update_needed')->default(false);
            });
        }

        if (! $schema->hasColumn('poll_translations', 'edited_by')) {
            $schema->table('poll_translations', function (Blueprint $table) {
                $table->unsignedInteger('edited_by')->nullable();
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('poll_translations');
    },
];
