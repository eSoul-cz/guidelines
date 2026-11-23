@Library(['testing', 'dockerHelpers', 'pipelines']) _

// eSoul's shared `phpPackagePipeline` step (library `pipelines`, eSoul-cz/jenkins-libraries): composer validate, PHPStan,
// PHP-CS-Fixer and composer audit, then PHPUnit on PHP 8.5, then PHPUnit on 8.4 and 8.6 after Composer re-resolved the
// dependencies on that version (a library without composer.lock). Main-branch builds trigger the Satis mirror.
//
// The build may run on the Quick node (label quick, reserved for the quick jobs of the infrastructure repositories)
// or on a general one, whichever has a free executor, and has to end within the 15 minutes that node allows.
phpPackagePipeline(
    dependencies: 'update',
    matrix: [
        [name: 'PHP 8.4', image: 'php:8.4', update: ''],
        [name: 'PHP 8.6', image: 'php:8.6', update: ''],
    ],
    satis: true,
    nodeLabel: 'quick',
    nodeLabelFallback: 'general',
)
