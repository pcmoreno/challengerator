APP = docker exec challengerator-challengeator-app-1

test:
	$(APP) php bin/phpunit --testsuite unit

test-unit:
	$(APP) php bin/phpunit --testsuite unit

coverage:
	$(APP) php bin/phpunit --testsuite unit --coverage-html var/coverage

cache-clear:
	$(APP) php bin/console cache:clear
