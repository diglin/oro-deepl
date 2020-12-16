# Diglin Oro Deepl Bundle

Translation is boring ? Not anymore with the powerful helps of the machine learning tool DeepL. Thanks to this bundle, you can for OroPlatform applications, you can translate all non-translated strings from the OroPlatform database and export them into a YAML or CSV file.

The translation will take care to not translate strings with such tags: "It could be cool if this {{ won't be translated }} or my dear %hello world% stay safe"

If you have created translation files into your own bundles, it needs to be loaded into the database before to proceed this command. You must use the command "oro:translation:convert" before.

## Special recommendation TODO

Translating via DeepL API costs. So for this reason, if any problem appears while exporting. Do not run again the same command right now. 
Instead, open the ...

### CSV Export 

In the case a CSV file is exported, the english value is exported also and can be used for human translation or for checking translated values.
Once you did the check of the translation, use the command "diglin:oro:deepl:translate:convert" to convert the CSV into a YAML file located into the translations folder then it will load the content into your OroPlatform database. On Production, you just need to let the generated files onto the translations folder, after your deployment, run the command "oro:platform:update --force" (do a database backup before to run it)

### YAML Export

The generated file can be used one to one to be loaded into your application. OroPlatform can also load it into your database if needed (e.g. to allow backend users to edit those translations).

## Installation

`composer require diglin/oro-deepl`

## Requirement

- OroPlatform 4.0.x | 4.1.x (may work on previous versions but not tested)

## Configuration

[DeepL API Key](https://www.deepl.com/pro#developer) is required if you want to translate the strings automatically. Please, go to OroPlatform Backoffice, menu `System > Configuration > System Configuration > Integrations > DeepL` or provide the key via the parameter `deepl-api-key` or set the key into the `var/deepl-license.key` file.

If no API Key is present, an error message will be displayed but empty translation will still be generated.

## Usage

Export empty strings of values in CSV to be translated

`bin/console --env=prod diglin:oro:deepl:translate:export --format csv --disable-deepl  de_DE`

Export translation into a CSV file, an english value will be available for translation check

`bin/console --env=prod diglin:oro:deepl:translate:export --format csv de_DE`

Export translation directly into a YAML file, existing data will be merged but overwritten if duplicate happens. The generated file will have a timestamp

`bin/console --env=prod diglin:oro:deepl:translate:export --format yml de_DE`

Export translation directly into a YAML file, existing data will be merged but overwritten if duplicate happens. The existing domain translation file (e.g. messages.de_DE.yml) will be overwritten.

`bin/console --env=prod diglin:oro:deepl:translate:export --format yml --overwrite de_DE`

Translate only messages domain

`bin/console --env=prod diglin:oro:deepl:translate:export --domains messages de_DE`

Calculate the number of characters will be exported for locale de_DE, useful to estimate DeepL cost

`bin/console --env=prod diglin:oro:deepl:translate:export --domains messages,jsmessages,workflows,validators,security --simulate de_DE`

Load previously generated CSV file and convert it to YAML, import it into the database and rebuild language cache without frontend interruption

`bin/console --env=prod diglin:oro:deepl:translate:convert --rebuild-cache translations/messages.de_DE.csv de_DE`

## Do you have a tip ?

Well, we admit that `diglin:oro:deepl:translate:export` is a long command but at least it describes what it does. If you prefer, you can use this shortcut instead: `d:o:d:t:e` 

## License

See [MIT](LICENSE.txt) 

## Author

* Diglin GmbH
* [https://www.diglin.com/](https://www.diglin.com/)
* [@diglin_](https://twitter.com/diglin_)
* [Follow me on github!](https://github.com/diglin)
