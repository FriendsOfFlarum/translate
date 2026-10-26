<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cached translation of one subject into one language (UNIQUE(subject, language)).
 *
 * @property int                        $id
 * @property string                     $language
 * @property string                     $provider
 * @property bool                       $update_needed
 * @property int|null                   $edited_by
 * @property \Carbon\Carbon|null        $created_at
 * @property \Carbon\Carbon|null        $updated_at
 * @property User|null                  $editedBy
 */
abstract class AbstractTranslation extends AbstractModel
{
    public $timestamps = true;

    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'update_needed' => 'boolean',
        'edited_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Foreign key column of the translated subject.
     */
    abstract public static function subjectKey(): string;

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * Human-edited rows are authoritative and never re-translated automatically.
     */
    public function isEdited(): bool
    {
        return $this->edited_by !== null;
    }

    public function isStale(): bool
    {
        return (bool) $this->update_needed;
    }
}
