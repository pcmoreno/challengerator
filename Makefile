APP = docker exec challengerator-challengeator-app-1

test:
	$(APP) php vendor/bin/phpunit --testsuite unit

test-unit:
	$(APP) php vendor/bin/phpunit --testsuite unit

coverage:
	$(APP) php vendor/bin/phpunit --testsuite unit --coverage-html var/coverage

cache-clear:
	$(APP) php bin/console cache:clear

migrate:
	$(APP) php bin/console doctrine:migrations:migrate --no-interaction

fixtures:
	$(APP) php bin/console doctrine:fixtures:load --no-interaction

generate-invite-codes:
	$(APP) php bin/console app:generate-invite-codes $(count)

create-super-user:
	$(APP) php bin/console app:create-super-user $(username) $(password)

test-db:
	$(APP) php bin/console doctrine:database:create --env=test --if-not-exists --no-interaction
	$(APP) php bin/console doctrine:migrations:migrate --env=test --no-interaction
