console.log('wps importer script')

const wpsGetCategoriesJson = (event) => {
    event.preventDefault()
    console.log('category form submitted')

    let baseUrl = document.querySelector('#category_base_url').value,
        endpoint = 'index.php?rest_route=/wp/v2/categories',
        perPage = '&per_page=100',
        startPage = document.querySelector('#category_last_page').value
    
    console.log('start page', startPage)

    axios.get(`${baseUrl}/${endpoint}${perPage}&parent=0`)
        .then(res => {
            let totalPages = Math.ceil(parseInt(res.headers["x-wp-totalpages"]))
            console.log('total pages', totalPages)
            let initUrl = `${baseUrl}/${endpoint}${perPage}`

            // handle parent categories first
            for (let page = startPage; page <= totalPages; page++) {
                console.log('working on page', page)
                alertWorking(page, totalPages, 'category')

                wpsGetParentCategories(initUrl, page, baseUrl, 0)
                    .then(resObj => {
                        console.log('finished parent category obj', resObj)
                        let newParentCategories = resObj.new_categories,
                            oldParentCategories = resObj.old_categories

                        newParentCategories.forEach((newParentCategory, index) => {
                            wpsGetChildCategories(initUrl, page, baseUrl, oldParentCategories[index].id, newParentCategory.term_id)
                        })
                    })
                
                // comment this out for it to keep going
                // if(page > 3) break;
            }

        })
        .catch(err => {
            console.error(err)
        })
}

const wpsGetChildCategories = (initUrl, page, baseUrl, oldParent, newParent) => {

    pageParam = `&page=${page}`
    targetUrl = `${initUrl}${pageParam}&order=desc`
    targetUrl = (parent == 0) ? `${initUrl}&parent=0` : `${initUrl}&parent=${oldParent}`
    
    axios.get(targetUrl)
        .then(res => {
            console.log(res)
            //console.log(`### [ PAGE ${page} ] ###\n${JSON.stringify(res.data, null, 4)}`);
            console.log(`### [ PAGE ${page} ] ### -> returned ${res.data.length} posts`);
            let categories = res.data

            let nonce = migrateData.nonce,
                headers = {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                postData = {
                    base_url: baseUrl,
                    page: page,
                    categories: categories,
                    parent: newParent
                },
                timeStamp = Date.now()


            axios.post(`${migrateData.siteUrl}/wp-json/wps_routes/v1/wps_migrate_categories?time_stamp=${timeStamp}`, postData, {
                    headers: headers
                })
                .then(res => {
                    console.log('Successful', res)
                    alertFinished(page, 'category')
                })
                .catch(err => {
                    console.error('Error', err)
                })
        })
        .catch(err => {
            console.error(err)

        })   

}

const wpsGetParentCategories = async (initUrl, page, baseUrl, parent) => {
    return new Promise((resolve) => {
        pageParam = `&page=${page}`
        targetUrl = `${initUrl}${pageParam}&order=desc`
        targetUrl = (parent == 0) ? `${initUrl}&parent=0` : `${initUrl}&parent=${parent}`
        
        axios.get(targetUrl)
            .then(res => {
                console.log(res)
                //console.log(`### [ PAGE ${page} ] ###\n${JSON.stringify(res.data, null, 4)}`);
                console.log(`### [ PAGE ${page} ] ### -> returned ${res.data.length} posts`);
                let categories = res.data

                let nonce = migrateData.nonce,
                    headers = {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce
                    },
                    postData = {
                        base_url: baseUrl,
                        page: page,
                        categories: categories,
                    },
                    timeStamp = Date.now()


                axios.post(`${migrateData.siteUrl}/wp-json/wps_routes/v1/wps_migrate_categories?time_stamp=${timeStamp}`, postData, {
                        headers: headers
                    })
                    .then(res => {
                        console.log('Successful', res)
                        let newCategories = res.data.inserted_categories
                        let oldCategories = res.data.posted_categories
                        let resObj = {
                            new_categories: newCategories,
                            old_categories: oldCategories
                        }
                        resolve(resObj)
                        alertFinished(page, 'category')
                    })
                    .catch(err => {
                        console.error('Error', err)
                    })
            })
            .catch(err => {
                console.error(err)

            })       
    });
    
}

const wpsGetPostsJson = (event) => {
    event.preventDefault()
    console.log('form submitted')

    let baseUrl = document.querySelector('#post_base_url').value,
        endpoint = 'index.php?rest_route=/wp/v2/posts',
        perPage = '&per_page=100',
        startPage = document.querySelector('#post_last_page').value
    
    console.log('start page', startPage)

    axios.get(`${baseUrl}/${endpoint}`)
        .then(res => {
            console.log(res.headers["x-wp-totalpages"])
            let totalPages = Math.ceil(parseInt(res.headers["x-wp-totalpages"]) / 10)
            let initUrl = `${baseUrl}/${endpoint}${perPage}`

            for (let page = startPage; page < totalPages; page++) {
                alertWorking(page, totalPages, 'post')

                wpsGetPagedPosts(initUrl, page, baseUrl)
                // comment this out for it to keep going
                // if(page > 1) break;
            }
        })
        .catch(err => {
            console.error(err)
        })
}

const alertWorking = (page, totalPages, type) => {
    let wrapper = (type == 'category') ? document.querySelector('.category-alert') : document.querySelector('.post-alert')
    let alertDiv = document.createElement('div')
    alertDiv.dataset.page = page
    alertDiv.innerText = `Working on page ${page} of ${totalPages} the migration!`

    wrapper.appendChild(alertDiv)
}

const alertFinished = (page, type) => {
    let parent = (type == 'category') ? document.querySelector('.category-alert') : document.querySelector('.post-alert')
    let targetElem = parent.querySelector(`div[data-page="${page}"]`)

    targetElem.innerHTML = targetElem.innerText + ' <span style="color: green;">Finished!</span>'
}

const wpsGetPagedPosts = (initUrl, page, baseUrl) => {
    pageParam = `&page=${page}`
    targetUrl = `${initUrl}${pageParam}`

    axios.get(targetUrl)
        .then(res => {
            console.log(res)
            console.log(`### [ PAGE ${page} ] ### -> returned ${res.data.length} posts`);
            let posts = res.data
            console.log(posts)
            let nonce = migrateData.nonce,
                headers = {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                postData = {
                    base_url: baseUrl,
                    page: page,
                    posts: posts
                },
                timeStamp = Date.now()

                axios.post(`${migrateData.siteUrl}/wp-json/wps_routes/v1/wps_migrate_posts?time_stamp=${timeStamp}`, postData, {
                        headers: headers
                    })
                    .then(res => {
                        console.log('Successful', res)
                        // update database with posts
                        console.log( page)
                        alertFinished(page, 'post')
                        // // if(posts.length > 0) {
                        // if(page < 5) {
                        //     return getRestApiPosts(initUrl, page+1)
                        // } else {
                        //     console.log('finishing the script');
                        //     return;
                        // }
                    })
                    .catch(err => {
                        console.error('Error', err)
                    })
            
        })
        .catch(err => {
            console.error(err)

        })
}

