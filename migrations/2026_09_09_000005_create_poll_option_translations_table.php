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

// See create_poll_translations_table: created unconditionally, FK only when poll_options exists.
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('poll_option_translations')) {
            $withPolls = $schema->hasTable('poll_options');

            $schema->create('poll_option_translations', function (Blueprint $table) use ($withPolls) {
                $table->increments('id');
                $table->unsignedInteger('option_id');
                $table->string('language', 10);
                $table->text('answer');
                $table->string('provider');
                $table->boolean('update_needed')->default(false);
                $table->unsignedInteger('edited_by')->nullable();
                $table->timestamps();

                $table->unique(['option_id', 'language']);
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();

                if ($withPolls) {
                    $table->foreign('option_id')->references('id')->on('poll_options')->cascadeOnDelete();
                }
            });

            return;
        }

        if (! $schema->hasColumn('poll_option_translations', 'update_needed')) {
            $schema->table('poll_option_translations', function (Blueprint $table) {
                $table->boolean('update_needed')->default(false);
            });
        }

        if (! $schema->hasColumn('poll_option_translations', 'edited_by')) {
            $schema->table('poll_option_translations', function (Blueprint $table) {
                $table->unsignedInteger('edited_by')->nullable();
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('poll_option_translations');
    },
];
