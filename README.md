<p align="center">
  <img src="root/app/www/public/images/watchsync-logo.png" alt="Watchsync" width="256" height="256">
</p>

# Watchsync

## NOTICE

This is not really made or ready for public consumption, if you found it and decide to use it then understand it is not fully working and automated yet. I will be doing FORCE PUSHES until it has all features at least some what working so the initial commit is a working one to base things on. Most things only work with manual intervention. As long as this notice is present, that will be the case.

## Purpose

Tool to keep users, libraries and watch history in sync across multiple media apps. Also used for disaster recovery on that history to populate a new database.

### Continous sync

Keep multiple media apps in sync with user, library & watch history so switching between them is seamless

### Disaster recovery

Database backups here can be used to restore a corrupted or broken media app database so all the watch history is restored

### Migrations

A one time use to copy over all libraries, users and watch history can be done to migrate from one app to another

### Information purposes

You can use the Library section to see how many movies/series are actually being watched and by which users

## Docker Compose

```yaml
services:
  watchsync:
    container_name: watchsync
    image: ghcr.io/notifiarr/watchsync:develop
    restart: unless-stopped
    ports:
      - 32399:80/tcp
    environment:
      - TZ=America/New_York
    volumes:
      - /home/watchsync/config:/config
```

## Setup

Add your media apps (main required, listeneres needed for syncing)

Sync to parity, this matches users and libraries across the apps based on main (make sure each user and library has a link across the apps or they wont sync)

Sync your libraries, this will populate the watchsync database with what each media app currently has

Sync your watch history for users

## Media matching

Currently the paths from main are used to match with paths on the listeners. As long as all the apps have the same path access then it will have no problem matching data, creating libraries, etc

## Conflict resolution

The system looks at all media apps, finds which one has the newest timestamp & that is what is used. If one app has it marked as finished then all apps get set to finished, otherwise it takes the highest progress time and uses that.

## More sync features

This app is built for a single purpose and that is not to solve every sync item and make perfect replicas of the apps, it does not match ratings for example or lists or favorites or any other "thing" that the media apps do. If you want something that does a lot more stuff with a more involved setup then I would recommend looking at some of the other sync apps out there as this is not your solution.

## License

This project is licensed under the MIT License.
