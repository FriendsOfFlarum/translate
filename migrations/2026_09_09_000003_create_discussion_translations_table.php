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

// Adopt-or-create, see create_post_translations_table.
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('discussion_translations')) {
            $schema->create('discussion_translations', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('discussion_id');
                $table->string('language', 10);
                $table->text('translation');
                $table->string('provider');
                $table->boolean('update_needed')->default(false);
                $table->unsignedInteger('edited_by')->nullable();
                $table->timestamps();

                $table->unique(['discussion_id', 'language']);
                $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
            });

            return;
        }

        if (! $schema->hasColumn('discussion_translations', 'update_needed')) {
            $schema->table('discussion_translations', function (Blueprint $table) {
                $table->boolean('update_needed')->default(false);
            });
        }

        if (! $schema->hasColumn('discussion_translations', 'edited_by')) {
            $schema->table('discussion_translations', function (Blueprint $table) {
                $table->unsignedInteger('edited_by')->nullable();
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('discussion_translations');
    },
];
