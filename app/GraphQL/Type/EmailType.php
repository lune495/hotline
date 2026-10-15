<?php

namespace App\GraphQL\Type;

use App\Models\Email;
use Carbon\Carbon;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

class EmailType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Email',
        'description' => 'Un email et sa réponse.',
        'model' => Email::class,
    ];

    public function fields(): array
    {
        return [
            'folder' => ['type' => Type::string()],
            'delivery_status' => ['type' => Type::string()],
            'bcc' => ['type' => Type::string()],
            'sent_at' => ['type' => Type::string()],
            'attachments' => ['type' => Type::listOf(\Rebing\GraphQL\Support\Facades\GraphQL::type('EmailAttachment'))],
            'hotline_id' => ['type' => Type::id()],
            'user_id' => ['type' => Type::id()],
            'uid' => ['type' => Type::id()],
            'id'                => ['type' => Type::id()],
            'mail_destinatire'  => ['type' => Type::string()],
            'mail_emetteur'     => ['type' => Type::string()],
            'objet'             => ['type' => Type::string()],
            'contenu'           => ['type' => Type::string()],
            'reponse'           => ['type' => Type::string()],  
            'cc'                => ['type' => Type::string()],
            'created_at'        => ['type' => Type::string()],
            'created_at_fr'     => ['type' => Type::string()],
            'updated_at'        => ['type' => Type::string()],
            'updated_at_fr'     => ['type' => Type::string()],
        ];
    }

    protected function resolveCreatedAtField($root, $args): ?string
    {
        return $this->formatDate($root, 'created_at', 'Y-m-d H:i:s');
    }

    protected function resolveCreatedAtFrField($root, $args): ?string
    {
        return $this->formatDate($root, 'created_at', 'd/m/Y H:i:s');
    }

    protected function resolveUpdatedAtField($root, $args): ?string
    {
        return $this->formatDate($root, 'updated_at', 'Y-m-d H:i:s');
    }

    protected function resolveUpdatedAtFrField($root, $args): ?string
    {
        return $this->formatDate($root, 'updated_at', 'd/m/Y H:i:s');
    }

    private function formatDate($root, string $field, string $format): ?string
    {
        $date = data_get($root, $field);

        return $date === null ? null : Carbon::parse($date)->format($format);
    }
}
