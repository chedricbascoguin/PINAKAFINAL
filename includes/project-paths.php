<?php
function rhuProjectPagePath(string $component): string
{
    $root = dirname(__DIR__);
    $name = basename($component) . '.php';
    return is_file($root . '/pages/' . $name)
        ? $root . '/pages/' . $name
        : $root . '/' . $name;
}
