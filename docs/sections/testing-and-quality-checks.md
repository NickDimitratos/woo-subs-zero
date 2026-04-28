# Testing and Quality Checks

Run PHPUnit:

- `./vendor/bin/phpunit -c phpunit.xml.dist`

Run syntax checks:

- `rg --files -g '*.php' | xargs -I{} php -l "{}"`
