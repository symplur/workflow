<?php

namespace Symplur\Workflow;

use Symfony\Component\Console\Output\OutputInterface;

class Manager
{
    private static $imagesToKeep = 5;

    private $hostIp;
    private $basePath;
    private $repoHost;
    private $clusterNames;
    private $strictCommitClusters;
    private $output;

    public function __construct(
        string $hostIp,
        string $basePath,
        string $repoHost,
        array $clusterNames,
        array $strictCommitClusters,
        OutputInterface $output
    ) {
        $this->hostIp = $hostIp;
        $this->basePath = $basePath;
        $this->repoHost = $repoHost;
        $this->clusterNames = $clusterNames;
        $this->strictCommitClusters = $strictCommitClusters;
        $this->output = $output;
    }

    public function deployCron($cluster, $codebase)
    {
        $taskId = $this->createNewTaskDefinition($cluster, $codebase);
        if ($taskId && $this->rolloutCron($cluster, $codebase, $taskId)) {
            $this->info(sprintf('Deployed scheduled task %s', $taskId));

            $this->cleanupObsoleteTaskDefinitions($cluster, $codebase);
            $this->cleanupObsoleteEcrImages($codebase);

            $this->info('Deployment successful');
        }
    }

    public function deployService($cluster, $codebase)
    {
        $taskId = $this->createNewTaskDefinition($cluster, $codebase);
        if ($taskId && $this->initiateServiceRollout($cluster, $codebase, $taskId)) {
            $this->info(sprintf('Graceful rollout of %s-%s has been queued', $cluster, $codebase));

            $this->cleanupObsoleteTaskDefinitions($cluster, $codebase);
            $this->cleanupObsoleteEcrImages($codebase);

            if ($this->monitorRollout($cluster, $codebase, $taskId)) {
                $this->info('Deployment successful');
            }
        }
    }

    private function createNewTaskDefinition($cluster, $codebase)
    {
        $imageName = $this->prepareImage($cluster, $codebase);
        if ($imageName) {
            $configs = $this->getContainerConfigs($cluster, $codebase);
            if ($configs) {
                return $this->makeNewTaskDefinition($cluster, $codebase, $imageName, $configs);
            }
        }
    }

    private function prepareImage($cluster, $codebase)
    {
        if ($this->repoStateIsValid($cluster)) {
            $imageName = $this->buildImage($codebase);
            if ($this->getEcrLogin() && $this->tagForEcs($imageName) && $this->pushImage($imageName)) {
                return $imageName;
            }
        }
    }

    public function build($repoName)
    {
        $this->buildImage($repoName);
    }

    public function run($codebase, $localPort = 0, $mountVolumes = false, $buildFirst = false)
    {
        $imageName = ($buildFirst ? $this->buildImage($codebase) : "$codebase:latest");

        $this->stop($codebase);

        $imageId = $this->execGetLastLine('docker images -q ' . $imageName);
        if (!$imageId) {
            $this->warn(sprintf('No such image "%s", so we\'ll build it now', $imageName));
            $imageName = $this->buildImage($codebase);
        }

        $this->info('Running locally');

        $volumes = '';
        if ($mountVolumes) {
            $this->line('Mounting local volumes');
            $volumes = '-v "' . rtrim($this->basePath, '/') . ':/opt"';
        }

        $pattern = 'docker run -d '
            . ($localPort ? sprintf('-p %s:80 ', $localPort) : '')
            . '--net=dockernet --add-host="docker-host:192.168.0.1" '
            . '%s --name symplur-%s %s';
        $command = sprintf($pattern, $volumes, $codebase, $imageName);
        $containerId = $this->execGetLastLine($command);

        $this->line(sprintf('Started container %s', $containerId));
    }

    public function stop($serviceName)
    {
        $this->execQuietly(sprintf('docker rm -f symplur-%s 2>/dev/null', $serviceName));
    }

    private function repoStateIsValid($cluster)
    {
        if (!in_array($cluster, $this->clusterNames)) {
            $this->error(sprintf('No such ECS cluster named "%s"', $cluster));
            return;
        }

        if (in_array($cluster, $this->strictCommitClusters)) {
            if ($this->hasUncommittedChanges()) {
                $this->error('You must commit your changes before continuing');
                return;

            } elseif ($this->hasUnpushedChanges()) {
                $this->error('You must push your changes to the origin server before continuing');
                return;
            }
        }

        return true;
    }

    private function buildImage($repoName)
    {
        $this->info('Refreshing Composer autoload cache');

        $composerPath = $this->findComposer();
        if (!$composerPath) {
            return;
        }

        $failed = $this->execQuietly($composerPath . ' dump-autoload');
        if ($failed) {
            $this->error('Failed refreshing Composer autoload classes');
            return;
        }

        $tag = $this->getImageTag();
        $imageName = "$repoName:$tag";

        $this->info(sprintf('Building Docker image %s', $imageName));

        $command = sprintf('DOCKER_BUILDKIT=0 docker build -t %s %s', $imageName, $this->basePath);
        $failed = $this->passthruGraceful($command);
        if ($failed) {
            $this->error(sprintf('Build failed with exit code %s', $failed));
            return;
        }

        $this->info(sprintf('Finished building Docker image %s', $imageName));

        return $imageName;
    }

    private function getImageTag()
    {
        if ($this->hasUncommittedChanges() || $this->hasUnpushedChanges()) {
            return join('-', [
                $this->getCurrentGitBranch(),
                $this->execGetLastLine('whoami'),
                gethostname(),
                time()
            ]);
        }

        return join('-', [
            $this->getCurrentGitBranch(),
            substr($this->getCurrentGitCommit(), 0, 7)
        ]);
    }

    private function findComposer()
    {
        $location = rtrim($this->basePath, '/') . '/composer.phar';
        if (!file_exists($location)) {
            $location = $this->execGetLastLine('which composer');
            if (!$location) {
                $location = $this->execGetLastLine('which composer.phar');
                if (!$location) {
                    $this->error(sprintf('Cannot find Composer in the filesystem'));
                    return;
                }
            }
        }

        return $location;
    }

    private function getEcrLogin()
    {
        $this->info('Making sure we have an AWS login');

        $nextCommand = $this
            ->execGetLastLine('aws ecr get-login-password --profile mfa --region us-east-1 '
                . '| docker login --username AWS --password-stdin ' . $this->repoHost);
        if (!$nextCommand) {
            $this->error('Failed determining the AWS ECR login command');
            return;
        }

        return true;
    }

    private function tagForEcs($imageName)
    {
        $failed = $this->execQuietly(sprintf('docker tag %1$s %2$s/%1$s', $imageName, $this->repoHost));

        return (!$failed);
    }

    private function pushImage($imageName)
    {
        $this->info('Pushing image to ECR');

        $command = sprintf('docker push %s/%s', $this->repoHost, $imageName);
        $this->line('If this fails, you can manually push by running the following command in your terminal:');
        $this->line('    ' . $command);

        $failed = $this->passthruGraceful($command);

        return (!$failed);
    }

    private function getContainerConfigs($cluster, $codebase)
    {
        $cmd = sprintf('aws ecs describe-task-definition --profile mfa --task-definition %s-%s', $cluster, $codebase);
        $task = $this->execGetJson($cmd);

        $configs = ($task['taskDefinition']['containerDefinitions'] ?? []);

        if (!$configs) {
            $this->error('Failed obtaining container configs');
            return;
        }

        return $configs;
    }

    private function makeNewTaskDefinition($cluster, $codebase, $imageName, array $configs)
    {
        $this->line(sprintf('Creating new task definition for %s-%s', $cluster, $codebase));

        foreach ($configs as &$config) {
            // IMPORTANT!!! We are assuming each container has the same image name.
            $config['image'] = $this->repoHost . '/' . $imageName;
        }

        $command = sprintf(
            'aws ecs register-task-definition --profile mfa --family %s-%s --container-definitions %s',
            $cluster,
            $codebase,
            escapeshellarg(json_encode($configs))
        );

        $data = $this->execGetJson($command);
        if (!$data) {
            $this->error('Failed making new task definition');
            return;
        }

        return $data['taskDefinition']['taskDefinitionArn'];
    }

    private function initiateServiceRollout($cluster, $codebase, $taskId)
    {
        $revision = $this->getRevisionFromTaskId($taskId);
        $this->line(sprintf('Updating %s %s service to revision %s', ucfirst($cluster), $codebase, $revision));

        $pattern = 'aws ecs update-service --profile mfa --cluster %s --service %s --task-definition %s';
        $command = sprintf($pattern, $cluster, $codebase, $taskId);
        $failed = $this->execQuietly($command);

        return (!$failed);
    }

    private function rolloutCron($cluster, $codebase, $taskId)
    {
        $revision = $this->getRevisionFromTaskId($taskId);
        $this->line(sprintf('Updating %s-%s cron to revision %s', $cluster, $codebase, $revision));

        $command = sprintf('aws events list-targets-by-rule --profile mfa --rule %s-%s', $cluster, $codebase);
        $result = $this->execGetJson($command);
        $target = @$result['Targets'][0];
        if (!$target) {
            $this->error('No target defined');
            return;
        }

        $target['EcsParameters']['TaskDefinitionArn'] = $taskId;

        $command = sprintf(
            'aws events put-targets --profile mfa --rule %s-%s --targets %s',
            $cluster,
            $codebase,
            escapeshellarg(json_encode($target))
        );

        $failed = $this->execQuietly($command);

        return (!$failed);
    }

    private function cleanupObsoleteTaskDefinitions($cluster, $codebase)
    {
        $this->info('Checking for obsolete task definitions');

        $activeArns = $this->getActiveTaskDefinitionArns($cluster, $codebase);

        $pattern = 'aws ecs list-task-definitions --profile mfa --family-prefix %s-%s';
        $taskDefinitions = $this->execGetJson(sprintf($pattern, $cluster, $codebase));

        $obsoleteArns = array_diff($taskDefinitions['taskDefinitionArns'], $activeArns);
        foreach ($obsoleteArns as $taskArn) {
            $def = $this->execGetJson(
                sprintf('aws ecs deregister-task-definition --profile mfa --task-definition %s', $taskArn)
            );
            if ($def['taskDefinition']['status'] == 'INACTIVE') {
                $this->line(sprintf('Deregistered obsolete task %s', $taskArn));
            }
        }
    }

    private function getActiveTaskDefinitionArns($cluster, $codebase)
    {
        $arns = [];

        $pattern = 'aws ecs describe-services --profile mfa --cluster %s --service %s';
        $services = $this->execGetJson(sprintf($pattern, $cluster, $codebase));
        if (@$services['services'][0]['deployments']) {
            foreach ($services['services'][0]['deployments'] as $deployment) {
                $arns[] = $deployment['taskDefinition'];
            }
        }

        $pattern = 'aws events list-targets-by-rule --profile mfa --rule %s-%s';
        $rules = $this->execGetJson(sprintf($pattern, $cluster, $codebase));
        if (@$rules['Targets']) {
            foreach ($rules['Targets'] as $target) {
                $arn = @$target['EcsParameters']['TaskDefinitionArn'];
                if ($arn) {
                    $arns[] = $arn;
                }
            }
        }

        return $arns;
    }

    private function cleanupObsoleteEcrImages($codebase)
    {
        $this->info('Checking for obsolete ECR images');

        $obsoleteDigests = $this->gatherObsoleteDigests( $codebase);
        if ($obsoleteDigests) {
            $pattern = 'aws ecr batch-delete-image --profile mfa --repository-name %s --image-ids %s';
            $digestBlob = 'imageDigest=' . join(' imageDigest=', $obsoleteDigests);
            $out = $this->execGetJson(sprintf($pattern, $codebase, $digestBlob));
            if (!empty($out['imageIds'])) {
                foreach ($out['imageIds'] as $image) {
                    $this->line(sprintf('Deleted obsolete image %s', (@$image['imageTag'] ?: $image['imageDigest'])));
                }
            }
            if (!empty($out['failures'])) {
                print_r($out['failures']);
            }
        }
    }

    private function gatherObsoleteDigests($codebase)
    {
        $data = $this->execGetJson(sprintf('aws ecr describe-images --profile mfa --repository-name %s', $codebase));

        $keep = ['latest'];

        foreach ($this->clusterNames as $cluster) {
            $this->addActiveImages($cluster, $codebase, $keep);
        }

        $timestamps = [];
        foreach ($data['imageDetails'] as $index => $image) {
            $timestamps[$index] = $image['imagePushedAt'];
        }

        array_multisort($timestamps, SORT_DESC, $data['imageDetails']);

        foreach (array_slice($data['imageDetails'], 0, self::$imagesToKeep) as $image) {
            foreach (($image['imageTags'] ?? []) as $tag) {
                $keep[] = $tag;
            }
        }

        $obsolete = [];
        foreach ($data['imageDetails'] as $image) {
            if (empty($image['imageTags']) || !array_intersect($keep, $image['imageTags'])) {
                $obsolete[] = $image['imageDigest'];
            }
        }

        return $obsolete;
    }

    private function addActiveImages($cluster, $codebase, array &$tagsToKeep)
    {
        $pattern = 'aws ecs describe-task-definition --profile mfa --task-definition %s-%s';
        $data = $this->execGetJson(sprintf($pattern, $cluster, $codebase));
        if ($data) {
            $prefix = $this->repoHost . '/' . $codebase . ':';
            $prefixLength = strlen($prefix);
            foreach ($data['taskDefinition']['containerDefinitions'] as $def) {
                if (substr($def['image'], 0, $prefixLength) == $prefix) {
                    $tag = substr($def['image'], $prefixLength);
                    if (!in_array($tag, $tagsToKeep)) {
                        $tagsToKeep[] = $tag;
                    }
                }
            }
        }
    }

    private function monitorRollout($cluster, $serviceName, $taskId)
    {
        $this->info('Monitoring rollout');

        $actualSuccesses = 0;
        $desiredSuccesses = 3;
        $command = sprintf('aws ecs describe-services --profile mfa --cluster %s --service %s', $cluster, $serviceName);
        while (true) {
            $stats = $this->getDeploymentStats($command, $taskId);
            if (!$stats) {
                $this->warn('Monitoring aborted because this service has a newer rollout in progress');
                return;
            }

            list($primary, $otherCount) = $stats;

            if ($primary) {
                $desired = $primary['desiredCount'];
                $pending = $primary['pendingCount'];
                $running = $primary['runningCount'];

                $pattern = 'Instances: %s other, %s new desired, %s new pending, %s new running';
                $this->line(sprintf($pattern, $otherCount, $desired, $pending, $running));

                if ($desired == $running) {
                    $actualSuccesses++;
                    if ($actualSuccesses >= $desiredSuccesses) {
                        $this->waitForOldInstances($command, $taskId);
                        break;
                    }
                }
            } else {
                $this->warn('No primary deployment found');
            }
            sleep(5);
        }

        return true;
    }

    private function waitForOldInstances($command, $taskId)
    {
        $this->info('New instance(s) running; waiting for old instance(s) to be removed');

        while (true) {
            $stats = $this->getDeploymentStats($command, $taskId);
            if (!$stats) {
                $this->warn('Monitoring aborted because this service has a newer rollout in progress');
                break;
            }

            list(, $otherCount) = $stats;
            if (!$otherCount) {
                break;
            }

            $this->line(sprintf('Waiting for %s old instance(s) to terminate', $otherCount));
            sleep(5);
        }
    }

    private function getDeploymentStats($command, $taskId)
    {
        $data = $this->execGetJson($command);

        $preferredRevision = $this->getRevisionFromTaskId($taskId);

        $otherCount = 0;
        $primary = null;
        foreach ($data['services'][0]['deployments'] as $deployment) {
            $revision = $this->getRevisionFromTaskId($deployment['taskDefinition']);
            if ($revision > $preferredRevision) {
                return null;
            } elseif ($deployment['status'] == 'PRIMARY' && $deployment['taskDefinition'] == $taskId) {
                $primary = $deployment;
            } else {
                $otherCount += $deployment['runningCount'];
            }
        }

        return [$primary, $otherCount];
    }

    private function getRevisionFromTaskId($taskId)
    {
        return substr(strrchr($taskId, ':'), 1);
    }

    private function hasUncommittedChanges()
    {
        return $this->execGetLastLine('git status -s');
    }

    private function hasUnpushedChanges()
    {
        return $this->execGetLines('git rev-list @{u}..');
    }

    private function getCurrentGitBranch()
    {
        return $this->execGetLastLine('git rev-parse --abbrev-ref HEAD');
    }

    private function getCurrentGitCommit()
    {
        return $this->execGetLastLine('git rev-parse HEAD');
    }

    private function getCurrentGitTag()
    {
        return $this->execGetLastLine('git describe --exact-match --abbrev=0 2>/dev/null');
    }

    private function info($msg)
    {
        $this->output->writeln("<info>$msg</info>");
    }

    private function line($msg)
    {
        $this->output->writeln($msg);
    }

    private function warn($msg)
    {
        return $this->output->writeln("<comment>$msg</comment>");
    }

    private function error($msg)
    {
        $this->output->writeln("<error>$msg</error>");
    }

    protected function execGetLines($command)
    {
        $output = [];
        exec($command, $output);
        if (!@$output[0]) {
            $output = [];
        }

        return $output;
    }

    protected function execGetLastLine($command)
    {
        return rtrim(exec($command));
    }

    protected function execInBackground($command)
    {
        $exitCode = rtrim(exec("$command >/dev/null 2>&1 & echo $!"));

        return $exitCode;
    }

    protected function execQuietly($command)
    {
        exec($command, $output, $exitCode);

        return $exitCode;
    }

    protected function execGetJson($command)
    {
        ob_start();
        $data = @json_decode(shell_exec($command), true);
        ob_end_clean();

        return $data;
    }

    protected function passthruGraceful($command)
    {
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = null;

        $proc = proc_open($command, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed proc_open');
        }

        $this->showOutput($pipes[1]); // stdout
        $this->showOutput($pipes[2]); // stderr

        $status = proc_get_status($proc);
        proc_close($proc);

        return @$status['exitcode'];
    }

    private function showOutput($descriptor, bool $isError = false)
    {
        $buffer = '';
        while (!feof($descriptor)) {
            $buffer .= fread($descriptor, 4096);

            while (true) {
                $newlineOffset = strpos($buffer, "\r\n");
                $len = 2;
                if ($newlineOffset === false) {
                    $crOffset = strpos($buffer, "\r");
                    if ($crOffset !== false) {
                        $buffer = '';
                    }

                    $newlineOffset = strpos($buffer, "\n");
                    $len = 1;
                    if ($newlineOffset === false) {
                        break;
                    }
                }

                $line = substr($buffer, 0, $newlineOffset);
                if ($isError) {
                    $this->error($line);
                } else {
                    $this->line($line);
                }
                $buffer = substr($buffer, $newlineOffset + $len);
            }
        }
    }
}
