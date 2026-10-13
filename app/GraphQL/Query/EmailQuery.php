<?php

namespace App\GraphQL\Query;

use App\Models\Email;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Query;
use Rebing\GraphQL\Support\Facades\GraphQL;

class EmailQuery extends Query
{
    protected $attributes = [
        'name' => 'emails',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('Email'));
    }

    public function args(): array
    {
        return [
            'id' => ['type' => Type::int()],
            'uid' => ['type' => Type::id()],
            'mail_destinatire' => ['type' => Type::string()],
            'mail_emetteur' => ['type' => Type::string()],
            'objet' => ['type' => Type::string()],
        ];
    }

    public function resolve($root, $args)
    {
        abort_unless(auth()->check(), 401);
        $hotline = auth()->user()->hotline;
        if (!$hotline || !$hotline->active) {
            return [];
        }
        $query = Email::query()->with('attachments')->where('hotline_id', $hotline->id);

        foreach (['id', 'uid', 'mail_destinatire', 'mail_emetteur', 'objet'] as $field) {
            if (isset($args[$field])) {
                $query->where($field, $args[$field]);
            }
        }

        return $query->orderByDesc('id')->get();
    }
}
