<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Command;

use Mezcalito\UxSearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractFieldSearch;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Symfony\Component\String\u;

#[AsCommand('survos:search:create')]
final class SearchCreateCommand
{
    public function __construct(private readonly string $projectDir) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity or DTO class carrying #[Field] metadata')] string $fieldClass,
        #[Argument('Search class short name, with or without Search suffix')] ?string $name = null,
        #[Option('Adapter name from mezcalito_ux_search.adapters')] string $adapter = 'default',
        #[Option('Index/table name; defaults to the field class')] ?string $index = null,
        #[Option('Output directory relative to the app project')] string $dir = 'src/Search',
        #[Option('PHP namespace for the generated class')] string $namespace = 'App\\Search',
    ): int {
        if (!class_exists($fieldClass)) {
            $io->error(sprintf('Class "%s" does not exist or is not autoloadable.', $fieldClass));
            return Command::FAILURE;
        }

        $short = (new \ReflectionClass($fieldClass))->getShortName();
        $className = $name ? u($name)->camel()->title()->toString() : $short . 'Search';
        if (!str_ends_with($className, 'Search')) {
            $className .= 'Search';
        }

        $targetDir = rtrim($this->projectDir . '/' . trim($dir, '/'), '/');
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $io->error(sprintf('Could not create "%s".', $targetDir));
            return Command::FAILURE;
        }

        $target = $targetDir . '/' . $className . '.php';
        if (file_exists($target)) {
            $io->error(sprintf('File already exists: %s', $target));
            return Command::FAILURE;
        }

        $fieldShort = $this->shortClass($fieldClass);
        $asSearchClass = $this->fqcn(AsSearch::class);
        $abstractFieldSearchClass = $this->fqcn(AbstractFieldSearch::class);
        $indexCode = $index === null ? $fieldShort . '::class' : var_export($index, true);
        $contents = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use {$fieldClass};
use {$asSearchClass};
use {$abstractFieldSearchClass};

#[AsSearch(index: {$indexCode}, adapter: '{$adapter}')]
final class {$className} extends AbstractFieldSearch
{
    protected function getFieldClass(array \$options = []): string
    {
        return {$fieldShort}::class;
    }

    public function build(array \$options = []): void
    {
        parent::build(\$options);

        \$this->setAdapterParameters(\$this->getAdapterParameters() + [
            'table' => 'change_me',
            'ftsTable' => 'change_me_fts',
            'selectColumns' => ['id'],
        ]);
    }
}
PHP;

        file_put_contents($target, $contents);
        $io->success(sprintf('Created %s', $target));

        return Command::SUCCESS;
    }

    private function shortClass(string $class): string
    {
        return substr($class, strrpos($class, '\\') + 1);
    }

    private function fqcn(string $class): string
    {
        return ltrim($class, '\\');
    }
}
