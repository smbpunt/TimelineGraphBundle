<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Fixtures\Traced;

/**
 * Traced service whose methods all come from a class outside the traced scope.
 *
 * @extends \ArrayObject<int, mixed>
 */
final class SampleCollection extends \ArrayObject
{
}
