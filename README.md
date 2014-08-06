# Getting Started

    export NETWORKNAME=dev-belasco
    mkdir $NETWORKNAME
    cd $NETWORKNAME

    vim network.yml # see share

    cloque utility:initialize-network
    cloque openvpn:rebuild-packages

    vim global/core/infrastructure.json # see share

    cloque infrastructure:compile global core
    cloque infrastructure:apply global core --aws-cloudformation 'Capabilities=["CAPABILITY_IAM"]'
    cloque infrastructure:reload-state global core

    cloque infrastructure:compile aws-usw2 core
    cloque infrastructure:apply aws-usw2 core
    cloque infrastructure:reload-state aws-usw2 core


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
