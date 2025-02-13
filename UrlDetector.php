<?php
/**
 * AlexeyGfi | alexeygfi@gmail.com
 * Пример класса, который заряжает потенциально многошаговую и длительную задачу
 * Используется инструментарий из ядра Битрикса
 *
 * Задача: перебрать урлы из списка и определить те, которые перестали отвечать
 * со статусом 200
 *
 * Зарядить задачу на выполнение: UrlDetector::bindChain();
 *
 * В коде использованы обращения к внешним классам-кешерам, смысл которых
 * в предоставлении нужного списка урлов для проверки
 */

namespace AlexeyGfi\CatalogHelpers;

use AlexeyGfi\CatalogSubmenu;
use AlexeyGfi\OftenAskedLinksSectionHelper;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\FileNotFoundException;
use Bitrix\Main\Update\Stepper;

class UrlDetector extends Stepper
{
    protected static string $processFileSuffix = '_collecting';

    public static function bindChain(): void
    {
        $logFile = static::getLogFileObj(static::$processFileSuffix);
        // Сбрасываем ранее собранную статистику
        $logFile->putContents('', File::REWRITE);

        if (Option::get("main.stepper." . static::getModuleId(), __CLASS__, "")) {
            // echo 'still working...';
            return;
        }

        Stepper::bindClass(
            __CLASS__, static::getModuleId(), 0
        );
    }

    protected static function getLogFileObj($filePrefix = ''): File
    {
        $server = Context::getCurrent()->getServer();
        return new File(
            $server->getDocumentRoot() .
            '/developer/catalogMenuUrlResponseCheck/checkLog' . $filePrefix . '.info'
        );
    }

    public static function getTitle(): string
    {
        return 'Сканер статусов урлов';
    }

    public static function getLogFileData(): string
    {
        $logFileObj = static::getLogFileObj();
        return date('d.m.Y H:i:s', $logFileObj->getModificationTime());
    }

    /**
     * Публикуем список "битых" урлов
     * @return false|string
     */
    public static function renderErrorStat(): false|string
    {
        $result = '';

        $errorUrls = static::analyzeStat();

        if (count($errorUrls)) {
            foreach ($errorUrls as $errorUrl) {
                $result .= <<<HTML
                <div class="error_row">
                    <strong>Код ответа</strong>: {$errorUrl['HTTP_RESPONSE_CODE']}<br>
                    <strong>Раздел</strong>: {$errorUrl['topLevelName']}<br>
                    <strong>Колонка</strong>: {$errorUrl['subLevelName']}<br>
                    <strong>Урл</strong>: {$errorUrl['urlInfo']['LINK']}
                </div>
HTML;
            }
        }

        return $result;
    }

    /**
     * Анализируем статистику и фильтруем битые урлы
     * @return array
     * @throws FileNotFoundException
     */
    public static function analyzeStat(): array
    {
        $fileObj = static::getLogFileObj();
        $statContent = $fileObj->getContents();

        $errorUrls = [];
        $rows = explode("\n", $statContent);
        foreach ($rows as $row) {
            $rowData = @unserialize($row, ['allowed_classes' => false]);
            $rowResponseStatus = $rowData['HTTP_RESPONSE_CODE'] ?? null;
            if ($rowResponseStatus && $rowResponseStatus != '200') {
                $errorUrls[] = $rowData;
            }
        }

        return $errorUrls;
    }

    /**
     * Шаг выполнение Степпера
     * @param array $option
     * @return bool
     */
    function execute(array &$option): bool
    {
        $steps = $option['steps'] ?: 0;
        $uriList = static::getUriList();

        $logFileProcessObj = static::getLogFileObj(static::$processFileSuffix);

        if ($steps >= count($uriList)) {
            $logFileResultObj = static::getLogFileObj();
            $filename = $logFileResultObj->getPath();
            $logFileResultObj->delete();

            $logFileProcessObj->rename($filename);

            return static::FINISH_EXECUTION;
        }

        $stepLimit = 5;
        $stepUris = array_slice($uriList, $steps, $stepLimit);

        foreach ($stepUris as $urlInfo) {
            $urlInfo['HTTP_RESPONSE_CODE'] = static::getUrlResponseStatus($urlInfo['urlInfo']['LINK']);
            $logFileProcessObj->putContents(serialize($urlInfo) . "\n", File::APPEND);
        }

        $option['steps'] = $steps + $stepLimit;
        $option['count'] = count($uriList);

        return static::CONTINUE_EXECUTION;
    }

    /**
     * Получаем список урлов для проверки
     * @return array
     */
    public static function getUriList(): array
    {
        $uriLib = [];
        // Обращаемся к кешеру (в закешированном виде не требует обращения к БД) за списком
        $submenuTree = CatalogSubmenu::getList();
        foreach ($submenuTree as $topLevel) {
            $levelSubmenu = $topLevel['SUBMENU'] ?? null;
            if (!$levelSubmenu) {
                continue;
            }

            foreach ($levelSubmenu as $subFolder) {
                $urlList = $subFolder['ITEMS'] ?? null;

                if (!$urlList) {
                    continue;
                }

                foreach ($urlList as $urlInfo) {

                    $uriLib[] = [
                        'topLevelName' => $topLevel['NAME'],
                        'subLevelName' => $subFolder['NAME'],
                        'urlInfo' => $urlInfo
                    ];
                }
            }
        }

        // Дополнительный блок урлов на проверку
        return array_merge($uriLib, static::getOftenAskedUniqueUrls());
    }

    /**
     * Получаем список урлов из блока "Часто задаваемых вопросов"
     * @return array
     */
    public static function getOftenAskedUniqueUrls(): array
    {
        // Обращаемся к кешеру (в закешированном виде не требует обращения к БД) за списком
        $sectionDemands = OftenAskedLinksSectionHelper::getList();

        $links = [];

        foreach ($sectionDemands as $sectionId => $sectionData) {
            foreach ($sectionData as $sdSerialized) {
                $l = unserialize($sdSerialized, ['allowed_classes' => false]);

                $url = $l['DESCRIPTION'] ?? '';
                if (!$url) {
                    continue;
                }

                // ...тоже берется из кешера
                $sectionInfo = CatalogSubmenu::getSectionInfo($sectionId);

                $links[] = [
                    'topLevelName' => $sectionInfo['NAME'] ?? '',
                    'subLevelName' => 'Перелинковка: ' . $l['VALUE'],
                    'urlInfo' => [
                        'LINK' => $url
                    ]
                ];
            }
        }

        return $links;
    }

    /**
     * Получаем статус ответа урла
     *
     * @param $url
     * @return false|mixed
     */
    public static function getUrlResponseStatus($url): mixed
    {
        $ch = curl_init('https://site.com' . $url);

        $httpCode = false;

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);

        if (!curl_errno($ch)) {
            $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } else {
            $httpCode = 'error: ' . curl_error($ch);
        }

        curl_close($ch);

        return $httpCode;
    }
}