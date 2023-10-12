<?php

namespace Crawler\Components;

use Crawler\Utils;

class SuperTable
{

    const POSITION_BEFORE_URL_TABLE = 'before-url-table';
    const POSITION_AFTER_URL_TABLE = 'after-url-table';

    public readonly string $aplCode;
    private string $title;

    /**
     * @var SuperTableColumn[]
     */
    private array $columns;
    private bool $positionBeforeUrlTable;
    private array $data;
    private string $emptyTableMessage;
    private string $currentOrderColumn;
    private string $currentOrderDirection = 'ASC';
    private string $uniqueId;

    /**
     * @param string $aplCode
     * @param string $title
     * @param string $emptyTableMessage
     * @param SuperTableColumn[] $columns
     * @param bool $positionBeforeUrlTable
     * @param string $currentOrderColumn
     * @param string $currentOrderDirection
     */
    public function __construct(string $aplCode, string $title, string $emptyTableMessage, array $columns, bool $positionBeforeUrlTable, string $currentOrderColumn, string $currentOrderDirection = 'ASC')
    {
        foreach ($columns as $column) {
            if (!($column instanceof SuperTableColumn)) {
                throw new \InvalidArgumentException('All columns must be instance of SuperTableColumn');
            }
        }

        $this->aplCode = $aplCode;
        $this->title = $title;
        $this->emptyTableMessage = $emptyTableMessage;
        $this->columns = [];
        foreach ($columns as $column) {
            $this->columns[$column->aplCode] = $column;
        }
        $this->positionBeforeUrlTable = $positionBeforeUrlTable;
        $this->currentOrderColumn = $currentOrderColumn;
        $this->currentOrderDirection = $currentOrderDirection;
        $this->uniqueId = substr(md5(rand(1000000, 9999999)), 0, 6);
    }

    /**
     * @param array $data
     * @return void
     */
    public function setData(array $data)
    {
        $this->data = $data;
        $this->sortData($this->currentOrderColumn, $this->currentOrderDirection);
    }

    /**
     * @return string
     */
    public function getHtmlOutput(): string
    {
        $output = '<section class="mb-5">';
        $output .= "<h2>" . htmlspecialchars($this->title) . "</h2>";
        if (!$this->data) {
            $output .= "<p>" . htmlspecialchars($this->emptyTableMessage) . "</p>";
            return $output;
        }

        $output .= "<table id='" . htmlspecialchars($this->uniqueId) . "' border='1' class='table table-bordered table-hover'>";
        $output .= "<thead>";
        foreach ($this->columns as $key => $column) {
            $direction = ($this->currentOrderColumn === $key && $this->currentOrderDirection === 'ASC') ? 'DESC' : 'ASC';
            $arrow = ($this->currentOrderColumn === $key) ? ($this->currentOrderDirection === 'ASC' ? '🔼' : '🔽') : '';
            $output .= "<th style='width:{$column->getWidthPx()}px' onclick='sortTable_" . htmlspecialchars($this->uniqueId) . "(\"" . htmlspecialchars($key) . "\", \"" . htmlspecialchars($direction) . "\")'>" . htmlspecialchars($column->name) . " {$arrow}</th>";
        }
        $output .= "</thead>";
        $output .= "<tbody>";
        foreach ($this->data as $row) {
            $output .= "<tr>";
            foreach ($this->columns as $key => $column) {
                $value = $row->{$key} ?? '';
                $formattedValue = $value;
                if ($column->formatter) {
                    $formattedValue = call_user_func($column->formatter, $value);
                }
                $output .= "<td data-value='" . htmlspecialchars($value) . "'>{$formattedValue}</td>";
            }
            $output .= "</tr>";
        }
        if (count($this->data) === 0) {
            $output .= "<tr><td colspan='" . count($this->columns) . "'>" . htmlspecialchars($this->emptyTableMessage) . "</td></tr>";
        }
        $output .= "</tbody>";
        $output .= "</table>";

        $output .= "
            <script>
            function sortTable_" . htmlspecialchars($this->uniqueId) . "(columnKey, direction) {
            const table = document.querySelector('#" . htmlspecialchars($this->uniqueId) . "');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const headerCells = Array.from(table.querySelectorAll('thead th'));
            const columnIndex = Array.from(table.querySelectorAll('thead th')).findIndex(th => th.textContent.trim().startsWith(columnKey));
        
            rows.sort((a, b) => {
                const aValue = a.children[columnIndex].getAttribute('data-value');
                const bValue = b.children[columnIndex].getAttribute('data-value');
        
                if (direction === 'ASC') {
                    return aValue > bValue ? 1 : aValue < bValue ? -1 : 0;
                } else {  // DESC
                    return aValue < bValue ? 1 : aValue > bValue ? -1 : 0;
                }
            });
        
            rows.forEach(row => tbody.appendChild(row));
            
            headerCells.forEach(th => {
                const text = th.textContent.trim();
                if (text.startsWith(columnKey)) {
                    th.textContent = direction === 'ASC' ? columnKey + ' 🔼' : columnKey + ' 🔽';
                } else {
                    // Odeberte šipky z ostatních sloupců
                    th.textContent = th.textContent.replace(' 🔼', '').replace(' 🔽', '');
                }
            });
        }
            </script>\n";

        $output .= '</section>';

        return $output;
    }

    /**
     * @return string
     */
    public function getConsoleOutput(): string
    {
        $titleOutput = $this->title . PHP_EOL . str_repeat('-', mb_strlen($this->title)) . PHP_EOL . PHP_EOL;;
        $output = Utils::getColorText($titleOutput, 'yellow');

        if (!$this->data) {
            $output .= Utils::getColorText($this->emptyTableMessage, 'gray') . PHP_EOL . PHP_EOL;
            return $output;
        }

        $headers = [];
        foreach ($this->columns as $key => $column) {
            $headers[] = str_pad($column->name, $column->width);
        }
        $output .= implode(' | ', $headers) . PHP_EOL;

        $repeat = array_sum(array_map(function ($column) {
                return $column->width;
            }, $this->columns)) + (count($this->columns) * 3) - 1;
        $output .= str_repeat('-', $repeat) . PHP_EOL;

        foreach ($this->data as $row) {
            $rowData = [];
            foreach ($this->columns as $key => $column) {
                $value = $row->{$key} ?? '';
                if (isset($column->formatter)) {
                    $value = call_user_func($column->formatter, $value);
                } else if (isset($column->renderer)) {
                    $value = call_user_func($column->renderer, $row);
                }

                if ($value && mb_strlen($value) > $column->width) {
                    // $value = Utils::truncateInTwoThirds($value, $column->width);
                }

                $rowData[] = str_pad($value, $column->width);
            }
            $output .= implode(' | ', $rowData) . PHP_EOL;
        }
        $output .= PHP_EOL;

        return $output;
    }

    public function getJsonOutput(): array
    {
        return [
            'aplCode' => $this->aplCode,
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->data,
            'position' => $this->positionBeforeUrlTable ? self::POSITION_BEFORE_URL_TABLE : self::POSITION_AFTER_URL_TABLE,
        ];
    }

    public function isPositionBeforeUrlTable(): bool
    {
        return $this->positionBeforeUrlTable;
    }

    private function sortData(string $columnKey, string $direction): void
    {
        usort($this->data, function ($a, $b) use ($columnKey, $direction) {
            $aValue = $a->{$columnKey} ?? '';
            $bValue = $b->{$columnKey} ?? '';

            if ($direction === 'ASC') {
                return $aValue > $bValue ? 1 : ($aValue < $bValue ? -1 : 0);
            } else {  // DESC
                return $aValue < $bValue ? 1 : ($aValue > $bValue ? -1 : 0);
            }
        });
    }
}