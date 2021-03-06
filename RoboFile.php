<?php
use Symfony\Component\Finder\Finder;

class Robofile extends \Robo\Tasks
{
    public function release()
    {
        $this->say("Releasing Robo");

        $this->taskGit()
            ->add('CHANGELOG.md')
            ->commit('updated')
            ->push()
            ->run();

        $this->taskGitHubRelease(\Robo\Runner::VERSION)
            ->uri('Codegyre/Robo')
            ->askDescription()
            ->run();
        
        $this->pharPublish();
    }

    public function tests()
    {
        $this->taskPHPUnit()
            ->run();
    }

    public function added($addition)
    {
        $this->taskChangelog()
            ->version(\Robo\Runner::VERSION)
            ->change($addition)
            ->run();
    }

    public function versionBump($version = null)
    {
        if (!$version) {
            $versionParts = explode('.', \Robo\Runner::VERSION);
            $versionParts[count($versionParts)-1]++;
            $version = implode('.', $versionParts);
        }
        $this->taskReplaceInFile(__DIR__.'/src/Runner.php')
            ->from("VERSION = '".\Robo\Runner::VERSION."'")
            ->to("VERSION = '".$version."'")
            ->run();
    }

    // publish docs
    public function docs()
    {
        $docs = [];
        foreach (get_declared_classes() as $task) {
            if (!preg_match('~Robo\\\Task.*?Task$~', $task)) continue;
            $docs[basename((new ReflectionClass($task))->getFileName(),'.php')][] = $task;
        }

        ksort($docs);
        $taskGenerator = $this->taskGenDoc('docs/tasks.md')->filterClasses(function (\ReflectionClass $r) {
            return !$r->isAbstract() or $r->isTrait();
        })->prepend("# Tasks");

        foreach ($docs as $file => $classes) {
            $taskGenerator->docClass("Robo\\Task\\$file");
            foreach ($classes as $task) {
                $taskGenerator->docClass($task);
            }
        }

        $taskGenerator->filterMethods(function(\ReflectionMethod $m) {
            if ($m->isConstructor() or $m->isDestructor()) return false;
            return $m->name != 'run' and $m->name != '__call' and $m->isPublic(); // methods are not documented
        })->processMethod(function (\ReflectionMethod $m, $text) {
            return "* " . $m->name . '('.implode(', ', $m->getParameters()).")\n";
        })->processClass(function(\ReflectionClass $refl, $text) {
            $text = str_replace("@method ".$refl->getShortName(),'*',$text);
            if ($refl->isTrait()) {
                return "## ".$refl->getShortName()."\n\n``` use ".$refl->getName().";```\n$text";
            } else {
                return "### ".$refl->getShortName()."\n".$text;
            }
        })->run();
    }

    public function pharBuild()
    {
        $files = Finder::create()->ignoreVCS(true)->files()->name('*.php')->in(__DIR__);
        $packer = $this->taskPackPhar('robo.phar');
        foreach ($files as $file) {
            $packer->addFile($file->getRelativePathname(), $file->getRealPath());
        }
        $packer->addFile('robo','robo')
            ->executable('robo')
            ->run();
    }

    public function pharPublish()
    {
        $this->pharBuild();
        rename('robo.phar', 'robo-release.phar');
        $this->taskGit()->checkout('gh-pages')->run();
        rename('robo-release.phar', 'robo.phar');
        $this->taskGit()
            ->add('robo.phar')
            ->commit('robo.phar published')
            ->push('origin','gh-pages')
            ->checkout('master')
            ->run();
    }

    public function watch()
    {
        $this->taskWatch()->monitor('composer.json', function() {
            $this->taskComposerUpdate()->run();
        })->run();
    }
    
}
