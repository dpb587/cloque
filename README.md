Some scripts to help manage an opinionated idea of BOSH and AWS environments.


# Getting Started

A network is a distinct, logical group of services. It should be unique and is
typically comprised of an environment level and unique name.

    $ export NETWORKNAME=dev-belasco
    $ mkdir $NETWORKNAME
    $ cd $NETWORKNAME

A `network.yml` file describes the various regions which are involved in the
network.

    $ vim network.yml # see share

The following will generate/upload SSH keys, IAM roles/users used by BOSH,
OpenVPN keys and certificates, and internally-used buckets.

    $ cloque utility:initialize-network

Once provisioned, you'll need to upload the OpenVPN packages to allow gateways
to download and apply their configurations.

    $ cloque openvpn:rebuild-packages

The global infrastructure is used to create additional IAM roles you'll use
across all regions.

    $ vim global/core/infrastructure.json # see share

Deploy those CloudFormation template.

    $ cloque infrastructure:go global core --aws-cloudformation 'Capabilities=["CAPABILITY_IAM"]'

Moar... deploy the region, bosh director, and target bosh...

    $ cloque infrastructure:go aws-usw2 core
    $ cloque infrastructure:go aws-usw2 bosh
    $ cloque bosh:compile aws-usw2 bosh
    $ cloque inception:start aws-usw2 \
      --subnet $(cloque infra:dump-state aws-usw2 core '.SubnetZ0PublicId') \
      --security-group $(cloque infra:dump-state aws-usw2 core '.TrustedPeerSecurityGroupId') \
      --security-group $(cloque infra:dump-state aws-usw2 core '.DirectorSecurityGroupId') \
    $ cloque inception:provision-bosh aws-usw2 ami-6b2b535b
    $ ( cd aws-usw2 && bosh target https://192.168.1.2:25555 && bosh create user "$USER" && bosh login "$USER" )

    # now do your own stuff
    $ cloque bosh:stemcell:upload aws-usw2 https://example.com/stemcell.tgz
    $ cloque bosh:go aws-usw2 logsearch


# OpenVPN Client

Someone might need to create a key pair for a new OpenVPN client...

    local$ mkdir ovpn && cd ovpn
    local$ openssl req \
      -subj "/C=US/ST=CO/L=Denver/O=ACME Corp/OU=client/CN=`hostname -s`-`date +%Y%m%da`/emailAddress=`git config user.email`" \
      -days 3650 -nodes \
      -new -out openvpn.csr \
      -newkey rsa:2048 -keyout openvpn.key
    local$ cat openvpn.csr

Then you'll need to sign it and send them a profile...

    cloque$ cloque openvpn:sign-certificate openvpn.csr
    cloque$ cloque openvpn:openvpn:generate-profile aws-usw2 jdoe-laptop-20140101a-20140805a

They should finish off the profile before installing it...

    local$ ( cat ; echo '<key>' ; cat openvpn.key ; echo '</key>' ) > openvpn.ovpn
    local$ mv openvpn.ovpn `grep -e '^remote ' openvpn.ovpn | awk '{ print $2 }' | sed 's/gateway\.//'`.ovpn
    local$ open *.ovpn


# Interesting Ideas

## `bosh:utility:package-downloads`

Sometimes when you're getting started on a package you'll want to download all the new blobs. If you leave a line
comment above the file path, this will dump a list of `mkdir`s and `wget`s for you to update your blob files.

    $ cloque bosh:utility:package-downloads $(ls packages)
    mkdir -p 'blobs/gearman-blobs'
    [ -f blobs/gearman-blobs/gearmand-1.0.6.tar.gz ] || wget -O 'blobs/gearman-blobs/gearmand-1.0.6.tar.gz' 'https://launchpad.net/gearmand/1.0/1.0.6/+download/gearmand-1.0.6.tar.gz'
    mkdir -p 'blobs/nginx-blobs'
    [ -f blobs/nginx-blobs/nginx-1.7.2.tar.gz ] || wget -O 'blobs/nginx-blobs/nginx-1.7.2.tar.gz' 'http://nginx.org/download/nginx-1.7.2.tar.gz'
    [ -f blobs/nginx-blobs/pcre-8.35.tar.gz ] || wget -O 'blobs/nginx-blobs/pcre-8.35.tar.gz' 'ftp://ftp.csx.cam.ac.uk/pub/software/programming/pcre/pcre-8.35.tar.gz'


## `bosh:utility:package-docker-build`

Sometimes when you're working on packages, it's easier to debug packaging scripts interactively. This will use Docker
containers to create a build environment with your blobs and other package dependencies (manually specified) for you to
debug with. Run `./packaging` or manually run steps iteratively.

    $ cloque bosh:utility:package-docker-build --export-package gearman.tgz gearman
    $ cloque bosh:utility:package-docker-build --import-package gearman.tgz php


## `bosh:list`

Sometimes you want to see what deployments BOSH is managing. By default it shows the standard output...

    $ cloque bosh:list prod-aws-us-east-1

    +-------------------+-----------------------------+----------------------------------------------+
    | Name              | Release(s)                  | Stemcell(s)                                  |
    +-------------------+-----------------------------+----------------------------------------------+
    | httpassetcache    | logsearch-shipper/0+dev.45  | bosh-aws-xen-ubuntu-trusty-go_agent-hvm/2624 |
    |                   | tle-httpassetcache/4+dev.11 |                                              |
    +-------------------+-----------------------------+----------------------------------------------+
    | logsearch         | logsearch-shipper/0+dev.45  | bosh-aws-xen-ubuntu-trusty-go_agent-hvm/2624 |
    |                   | logsearch/16+dev.8          |                                              |
    ...snip...

But sometimes you might want it in a different format, like YAML...

    $ cloque bosh:list --format yaml prod-aws-us-east-1
    httpassetcache:
        name: httpassetcache
        release:
            - logsearch-shipper/0+dev.43
            - tle-httpassetcache/4+dev.11
        stemcell:
            - bosh-aws-xen-ubuntu-trusty-go_agent-hvm/2624
    httpforwarders:
        name: httpforwarders
        release:
            - logsearch-shipper/0+dev.44
            - tle-httpforwarders/4+dev.2
        stemcell:
            - bosh-aws-xen-ubuntu-trusty-go_agent/2624
    ...snip...

Or in JSON for automating tasks with helpers like `jq`...

    $ for DEPLOYMENT in $(cloque bosh:list --format yaml prod-aws-us-east-1 | jq -r '.[] | .name') ; ...snip...


## `bosh:snapshot:cleanup-self`

Sometimes you need help cleaning up all the snapshots the BOSH director creates for itself. This command will delete
snapshots older than a given period:

    $ cloque bosh:snapshot:cleanup-self aws-use1 3d
    snap-bc877012 -> 2014-08-15T05:59:09+00:00 -> deleted
    snap-c187706f -> 2014-08-15T05:59:17+00:00 -> deleted
    snap-8585722b -> 2014-08-15T05:59:32+00:00 -> deleted
    snap-529444fc -> 2014-08-16T05:59:09+00:00 -> retained
    snap-7a9545d4 -> 2014-08-16T05:59:24+00:00 -> retained
    snap-3d974793 -> 2014-08-16T05:59:40+00:00 -> retained
    snap-9577c53b -> 2014-08-17T05:59:06+00:00 -> retained
    snap-d377c57d -> 2014-08-17T05:59:13+00:00 -> retained
    snap-a175c70f -> 2014-08-17T05:59:29+00:00 -> retained
    snap-ef51cd41 -> 2014-08-18T05:59:03+00:00 -> retained
    snap-dd52ce73 -> 2014-08-18T05:59:18+00:00 -> retained
    snap-b553cf1b -> 2014-08-18T05:59:26+00:00 -> retained


## `bosh:snapshot:cleanup`

Sometimes you need help cleaning up all the snapshots BOSH creates. This command will invoke a custom function that you
define in order to determine whether a snapshot should be deleted or retained. Your script should be located in one of
the following locations (first file found is used):

 0. `{director}/{deployment}/cloque/bosh-snapshot-cleanup.php`
 0. `{director}/common/cloque/bosh-snapshot-cleanup.php`
 0. `common/cloque/bosh-snapshot-cleanup.php`

The script must return a function with the following definition which will return `true` if a snapshot should be
deleted:

    function (
        array $snapshot = [
            'job' => string,
            'index' => integer,
            'snapshot_cid' => string,
            'created_at' => DateTime,
            'clean' => Boolean,
        ],
        Symfony\Component\Console\Input\InputInterface $input,
        Symfony\Component\Console\Output\OutputInterface $output,
    ) -> Boolean

For example:

    $ cat common/cloque/bosh-snapshot-cleanup.php
    <?php

    $expires = new DateTime('7 days ago');

    return function ($snapshot) use ($expires) {
        return ($snapshot['created_at'] < $expires);
    };

    $ cloque bosh:snapshot:cleanup prod-aws-us-east-1 logsearch
    snap-57f770f8 -> 2014-08-10T07:03:01+00:00 -> dirty -> deleted
    snap-b1b0d01e -> 2014-08-11T07:03:17+00:00 -> dirty -> retained
    snap-6eb5f9c1 -> 2014-08-12T07:04:23+00:00 -> dirty -> retained
    snap-b9e1c916 -> 2014-08-13T07:03:32+00:00 -> dirty -> retained
    snap-25deca8a -> 2014-08-14T07:03:47+00:00 -> dirty -> retained
    snap-053acbab -> 2014-08-15T07:03:45+00:00 -> dirty -> retained
    snap-7df527d3 -> 2014-08-16T07:03:16+00:00 -> dirty -> retained
    snap-1d2894b3 -> 2014-08-17T07:02:55+00:00 -> dirty -> retained
    snap-9d34aa33 -> 2014-08-18T07:03:28+00:00 -> dirty -> retained


# Open Source

[MIT License](./LICENSE.txt)
