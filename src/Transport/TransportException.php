<?php

namespace IbnKhafagah\SupportClient\Transport;

use RuntimeException;

/**
 * The CRM gave no usable answer: unreachable, a timeout, a 5xx, a redirect,
 * or a body that is not JSON. Always temporary from the caller's side: try
 * again later (a new ticket goes to the outbox, a read shows "service
 * unavailable").
 */
final class TransportException extends RuntimeException {}
