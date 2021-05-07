<?php
/**
 * Diglin GmbH - Switzerland.
 *
 * @author      Sylvain Rayé <support at diglin.com>
 * @category    DigitalDrink - OroCommerce
 * @copyright   2020 - Diglin (https://www.diglin.com)
 */

namespace Diglin\Bundle\DeeplBundle\Command;

use Oro\Bundle\TranslationBundle\Provider\LanguageProvider;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

class TranslationLoadCommand extends Command
{
    const SUPPORTED_DOMAINS = [
        'messages',
        'jsmessages',
        'workflows',
        'validators',
        'security',
    ];
    private ManagerRegistry  $doctrine;
    private LanguageProvider $languageProvider;
    private string           $defaultTranslationPath;
    /**
     * @var TranslatorInterface
     */
    private TranslatorInterface $translator;

    public function __construct(
        ManagerRegistry $doctrine,
        LanguageProvider $languageProvider,
        string $defaultTranslationPath,
        TranslatorInterface $translator,
        string $name = null
    ) {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->languageProvider = $languageProvider;
        $this->defaultTranslationPath = $defaultTranslationPath;
        $this->translator = $translator;
    }

    public function configure()
    {
        $description = <<<DESC
Load translation from a CSV file then merge and save it into a YAML file per locale and domain.
DESC;

        $this->setName('diglin:oro:deepl:translate:convert');
        $this->setDescription($description);
        $this->addArgument(
            'source',
            InputArgument::REQUIRED,
            'Source of the file'
        )
            ->addArgument(
                'locale',
                InputArgument::REQUIRED,
                'Locale of the translation to update. e.g. de_DE'
            );

        $this->addOption(
            'domain',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Message domains. Domain will be detected via the filename e.g. domain.locale.yml if this option is not provided. Supported domains are: ' . implode(',', self::SUPPORTED_DOMAINS)
        )
            ->addOption(
                'rebuild-cache',
                'r',
                InputOption::VALUE_NONE,
                'Rebuild translation cache'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getArgument('source');
        $locale = $input->getArgument('locale');
        $domain = $input->getOption('domain');

        if (!file_exists($file)) {
            $output->writeln(sprintf('<error>Aborted. Filename %s does not exist.</error>', $file));

            return 0;
        }

        // Try to detect automatically the domain from filename
        if (empty($domain)) {
            $filename = pathinfo($file, PATHINFO_BASENAME);
            $filenameParts = explode('.', $filename);
            if (count($filenameParts) === 3) {
                if (in_array($filenameParts[0], self::SUPPORTED_DOMAINS)) {
                    $domain = $filenameParts[0];
                }
            }
        }

        $pathInfoExt = pathinfo($file, PATHINFO_EXTENSION);
        if ($pathInfoExt !== 'csv') {
            $output->writeln(sprintf('<error>Aborted. Filename %s MUST be a CSV file.</error>', $file));

            return 0;
        }

        $availableLocales = $this->languageProvider->getAvailableLanguageCodes(true);

        $output->writeln(
            sprintf(
                '<info>Available locales</info>: %s. <info>Should be processed:</info> %s.',
                implode(', ', $availableLocales),
                $locale
            )
        );

        if (!in_array($locale, $availableLocales)) {
            $output->writeln(sprintf('<error>Aborted. Locale %s not supported</error>', $locale));

            return 0;
        }

        $style = new SymfonyStyle($input, $output);

        $target = $this->defaultTranslationPath . DIRECTORY_SEPARATOR . $domain . '.' . $locale . '.yml';

        $translations = [];
        if (file_exists($target)) {
            $backup = $this->defaultTranslationPath . DIRECTORY_SEPARATOR . $domain . '.' . $locale . '-' . date('dmY-His') . '.yml.backup';
            copy($target, $backup);

            $translations = Yaml::parseFile($target);
        }

        switch ($style->ask(sprintf('Your translation file located into %s will be merged with your existing translation located at %s. Confirm y/n ?', $file, $target), 'n')) {
            case 'n':
                $output->writeln(sprintf('<error>Import of %s aborted.</error>', $file));

                return 0;
            case 'y':
                break;
        }

        $newTranslations = [];
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);
        while ($row = fgetcsv($handle)) {
            $line = array_combine(array_values($headers), $row);
            if (empty($line[$locale])) {
                continue;
            }
            $newTranslations[$line['key']] = $line[$locale];
        }

        $translated = array_merge($translations, $newTranslations);
        file_put_contents($target, YAML::dump($translated));

        fclose($handle);

        $output->writeln(sprintf('<fg=green>The file %s has been merged with %s with success.</>', $file, $target));
        $output->writeln(sprintf('<fg=yellow>Then run bin/console --env=prod cache:clear && bin/console --env=prod oro:translation:load.</>'));
    }
}
