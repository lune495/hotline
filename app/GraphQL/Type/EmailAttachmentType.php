<?php

namespace App\GraphQL\Type;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

class EmailAttachmentType extends GraphQLType
{
    protected $attributes = ['name' => 'EmailAttachment', 'model' => \App\Models\EmailAttachment::class];

    public function fields(): array
    {
        return ['id' => ['type' => Type::id()], 'name' => ['type' => Type::string()], 'mime_type' => ['type' => Type::string()], 'size' => ['type' => Type::float()]];
    }
}
