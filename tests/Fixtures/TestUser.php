<?php

namespace Mpociot\ApiDoc\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class TestUser extends Model
{
	public static function factory()
	{
		return new class
		{
			public function make(array $attributes = [])
			{
				$user = new TestUser();
				$user->id = $attributes['id'] ?? 4;
				$user->first_name = $attributes['first_name'] ?? 'Tested';
				$user->last_name = $attributes['last_name'] ?? 'Again';
				$user->email = $attributes['email'] ?? 'a@b.com';

				return $user;
			}
		};
	}
}
