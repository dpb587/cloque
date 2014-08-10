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


# Useful Commands

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
