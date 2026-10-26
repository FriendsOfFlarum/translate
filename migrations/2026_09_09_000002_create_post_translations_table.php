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

// Adopt-or-create: on forums upgraded from ianm/translate the table already exists
// (with its data); only columns added by later 1.x migrations may be missing.
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('post_translations')) {
            $schema->create('post_translations', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('post_id');
                $table->string('language', 10);
                $table->mediumText('content')->nullable();
                $table->string('provider');
                $table->boolean('update_needed')->default(false);
                $table->unsignedInteger('edited_by')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['post_id', 'language']);
                $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
            });

            return;
        }

        if (! $schema->hasColumn('post_translations', 'update_needed')) {
            $schema->table('post_translations', function (Blueprint $table) {
                $table->boolean('update_needed')->default(false);
            });
        }

        if (! $schema->hasColumn('post_translations', 'edited_by')) {
            $schema->table('post_translations', function (Blueprint $table) {
                $table->unsignedInteger('edited_by')->nullable();
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('post_translations');
    },
];
