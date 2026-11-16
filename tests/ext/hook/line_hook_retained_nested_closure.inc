<?php
return function () use ($capture) {
    return function () use ($capture) {
        return 'called';
    };
};
