// =============================================================================
//  Tiny S3 — Jenkins Pipeline
//
//  Stages
//  ──────
//  1. Checkout      – clone / update the workspace
//  2. Dependencies  – install Composer dev-dependencies
//  3. Test          – run the full PHPUnit suite (no coverage, fast feedback)
//  4. Coverage      – re-run with Xdebug/PCOV to produce:
//                       • coverage/html/        HTML report  (interactive)
//                       • coverage/clover.xml   Clover XML   (Clover PHP plugin)
//                       • coverage/cobertura.xml Cobertura   (Cobertura plugin)
//                       • coverage/junit.xml    JUnit XML    (built-in test trends)
//                       • coverage/coverage.txt plain-text summary
//  5. Publish       – attach all reports to the build
//
//  Required Jenkins plugins
//  ────────────────────────
//  • Pipeline (workflow-aggregator)
//  • HTML Publisher            → publishHTML  (interactive HTML coverage)
//  • JUnit Plugin              → junit        (test trends / failures)
//  • Cobertura Plugin          → cobertura    (line/branch coverage trend)
//    OR Clover PHP Plugin      → cloverphp    (alternative coverage trend)
//
//  Agent requirements
//  ──────────────────
//  The build agent must have:
//    • PHP ≥ 8.1    with the Xdebug ≥ 3 extension installed
//    • Composer 2.x
//  Xdebug must NOT be in debug mode by default; the pipeline activates
//  coverage mode only for the Coverage stage via XDEBUG_MODE=coverage.
//
//  Environment variables (set in Jenkins / pipeline)
//  ─────────────────────────────────────────────────
//  PHP_BIN   – path to the PHP binary   (default: "php")
//  COMPOSER  – path to composer.phar    (default: "composer")
// =============================================================================

pipeline {

    agent any

    // ── configurable defaults (override in Jenkins → Configure → Build Env) ──
    environment {
        PHP_BIN  = "${env.PHP_BIN  ?: 'php'}"
        COMPOSER = "${env.COMPOSER ?: 'composer'}"
    }

    options {
        timestamps()
        timeout(time: 15, unit: 'MINUTES')
        // Keep HTML reports for the last N builds
        buildDiscarder(logRotator(numToKeepStr: '10', artifactNumToKeepStr: '5'))
    }

    stages {

        // ── 1. Checkout ───────────────────────────────────────────────────────
        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        // ── 2. Dependencies ───────────────────────────────────────────────────
        stage('Dependencies') {
            steps {
                sh '''
                    ${COMPOSER} install \
                        --no-interaction \
                        --prefer-dist \
                        --optimize-autoloader \
                        --no-progress
                '''
            }
        }

        // ── 3. Test (no coverage — fast) ──────────────────────────────────────
        stage('Test') {
            steps {
                sh '''
                    mkdir -p coverage
                    ${PHP_BIN} vendor/bin/phpunit \
                        --log-junit coverage/junit.xml
                '''
            }
            post {
                always {
                    // Publish JUnit XML → build test trend graph
                    junit 'coverage/junit.xml'
                }
            }
        }

        // ── 4. Coverage ───────────────────────────────────────────────────────
        stage('Coverage') {
            environment {
                // Activate Xdebug coverage mode for this stage only.
                // If you use PCOV instead of Xdebug, remove this line and
                // ensure pcov is loaded: php -d extension=pcov.so
                XDEBUG_MODE = 'coverage'
            }
            steps {
                sh '''
                    mkdir -p coverage
                    ${PHP_BIN} vendor/bin/phpunit \
                        --log-junit coverage/junit.xml
                    # Print the plain-text summary to the build log
                    cat coverage/coverage.txt || true
                '''
            }
            post {
                always {
                    // ── JUnit trend (overwrite with coverage-run results) ────
                    junit 'coverage/junit.xml'

                    // ── HTML Coverage Report ─────────────────────────────────
                    // Opens as "Coverage Report" link on the build page.
                    // Looks and behaves like JaCoCo / Istanbul HTML reports.
                    publishHTML(target: [
                        reportName:            'Coverage Report',
                        reportDir:             'coverage/html',
                        reportFiles:           'index.html',
                        alwaysLinkToLastBuild: true,
                        keepAll:               true,
                        allowMissing:          false
                    ])

                    // ── Cobertura XML trend (line + branch %) ───────────────
                    // Requires the "Cobertura Plugin" in Jenkins.
                    // Shows a coverage trend chart on the project page —
                    // identical in purpose to the JaCoCo or Java Coverage bar.
                    cobertura(
                        coberturaReportFile:          'coverage/cobertura.xml',
                        onlyStable:                   false,
                        failNoReports:                false,
                        failUnhealthy:                false,
                        failUnstable:                 false,
                        autoUpdateHealth:             true,
                        autoUpdateStability:          true,
                        // Thresholds — adjust to your project's targets
                        lineCoverageTargets:          '80, 60, 0',
                        conditionalCoverageTargets:   '70, 50, 0',
                        methodCoverageTargets:        '80, 60, 0'
                    )

                    // ── Plain-text coverage summary as a build artefact ──────
                    archiveArtifacts artifacts: 'coverage/coverage.txt',
                                     allowEmptyArchive: true

                    // ── Clover XML (optional — needs Clover PHP plugin) ──────
                    // Uncomment if you have the "Clover PHP" plugin installed:
                    // step([
                    //     $class:                    'CloverPublisher',
                    //     cloverReportDir:           'coverage',
                    //     cloverReportFileName:      'clover.xml',
                    //     healthyTarget:             [methodCoverage: 70, conditionalCoverage: 80, statementCoverage: 80],
                    //     unhealthyTarget:           [methodCoverage: 50, conditionalCoverage: 50, statementCoverage: 50],
                    //     failingTarget:             [methodCoverage:  0, conditionalCoverage:  0, statementCoverage:  0]
                    // ])
                }
            }
        }
    }

    // ── Global post actions ───────────────────────────────────────────────────
    post {
        failure {
            echo 'Build failed — check test output and coverage log above.'
        }
        success {
            echo 'All tests passed. Coverage reports published.'
        }
    }
}
