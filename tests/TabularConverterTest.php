<?php

use Asyncopath\Data\TabularConverter;

class PersonalInfoAddress
{
    public function __construct(public string $street, public int $number)
    {

    }
}

class PersonalInfo
{
    public function __construct(
        public string $name,
        public int $age,
        /** @var PersonalInfoAddress[] */
        public array $address
    ) {

    }
}

class Dto
{
    public function __construct(public PersonalInfo $info, public ?string $role)
    {

    }
}




it('should tabular da table', function (): void {
    $tabluarTest = [
        ["id" => "1","street" => 'Sesamo 41', "number" => 40, "name" => "John", "age" => "20", "role" => "ADMIN",],
        ["id" => "2","street" => "Climber Wimbler", "number" => 41, "name" => "Jame", "age" => "22", "role" => "ADMIN",],
        ["id" => "1","street" => "Jason Notation", "number" => 42, "name" => "John", "age" => "20", "role" => "ADMIN",],
        ["id" => "1","street" => null, "number" => null, "name" => "John", "age" => "20", "role" => null],
        ["id" => "1","street" => "third stret", "number" => 12, "name" => "John", "age" => "20", "role" => null],
    ];
    $tabularConverter = new TabularConverter();
    $tabularConverter->walkTuple($tabluarTest, Dto::class);

});
