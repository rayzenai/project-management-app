<?php

namespace App\Support\Forms;

use RuntimeException;

/** A draft the builder sent that can't be kept as it is; the message says why, in the builder's words. */
final class InvalidFormSchema extends RuntimeException {}
