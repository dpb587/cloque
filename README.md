# Getting Started

    export NETWORKNAME=dev-belasco
    mkdir $NETWORKNAME
    cd $NETWORKNAME

    vim network.yml # see share

    cloque utility:initialize-network
    cloque openvpn:rebuild-packages

    vim global/core/infrastructure.json # see share

    cloque infrastructure:go global core --aws-cloudformation 'Capabilities=["CAPABILITY_IAM"]'

    cloque infrastructure:go aws-usw2 core

    cloque infrastructure:go aws-usw2 bosh

    cloque bosh:compile aws-usw2 bosh

    cloque inception:start aws-usw2 \
      --subnet `cat compiled/aws-usw2/core/infrastructure--state.json | jq -r '.SubnetZ0PublicId'` \
      --security-group `cat compiled/aws-usw2/core/infrastructure--state.json | jq -r '.TrustedPeerSecurityGroupId'` \
      --security-group `cat compiled/aws-usw2/bosh/infrastructure--state.json | jq -r '.DirectorSecurityGroupId'`

    cloque inception:provision-bosh aws-usw2 ami-6b2b535b

    ( cd aws-usw2 && bosh target https://192.168.1.2:25555 && bosh create user "$USER" && bosh login "$USER" )

    # now do stuff
    cloque bosh:stemcell:upload aws-usw2 https://example.com/stemcell.tgz
    cloque bosh:go aws-usw2 logsearch


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
