<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

final class Tables
{
    public const BATCHES = 'xbs_report_batches';
    public const DOMAINS = 'xbs_domains';
    public const IP_ADDRESSES = 'xbs_ip_addresses';
    public const OUTBOUNDS = 'xbs_outbounds';
    public const MINUTE = 'xbs_traffic_minute';
    public const HOUR_NODE_USER = 'xbs_traffic_hour_node_user';
    public const HOUR_NODE_USER_DOMAIN = 'xbs_traffic_hour_node_user_domain';

    /** Rollup rows store unrecognized domains under this id instead of NULL. */
    public const UNKNOWN_DOMAIN_ID = 0;
}
